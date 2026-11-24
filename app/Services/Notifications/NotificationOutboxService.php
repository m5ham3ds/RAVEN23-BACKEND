<?php

namespace App\Services\Notifications;

use App\Jobs\DeliverNotificationOutboxJob;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class NotificationOutboxService
{
    public function queueEmailVerification(User $user, string $token, int $expiresInMinutes): string
    {
        $tokenHash = hash('sha256', $token);

        return $this->enqueue(
            $user,
            'email_verification',
            ['token' => $token, 'expires_in_minutes' => max(1, $expiresInMinutes)],
            'email-verification:'.$user->getKey().':'.$tokenHash,
        );
    }

    public function queuePasswordReset(User $user, string $token): string
    {
        $tokenHash = hash('sha256', $token);

        return $this->enqueue(
            $user,
            'password_reset',
            ['token' => $token],
            'password-reset:'.$user->getKey().':'.$tokenHash,
        );
    }

    private function enqueue(User $user, string $notificationType, array $payload, string $eventKey): string
    {
        if (! in_array($notificationType, ['email_verification', 'password_reset'], true)) {
            throw new RuntimeException('Unsupported notification outbox type.');
        }

        $idempotencyKey = hash_hmac('sha256', $eventKey, (string) config('app.key'));
        $outboxId = (string) Str::uuid();
        $encryptedPayload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $notificationType, $idempotencyKey, $outboxId, $encryptedPayload): string {
            DB::table('notification_outbox')->insertOrIgnore([
                'id' => $outboxId,
                'user_id' => $user->getKey(),
                'idempotency_key' => $idempotencyKey,
                'notification_type' => $notificationType,
                'encrypted_payload' => $encryptedPayload,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = DB::table('notification_outbox')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw new RuntimeException('The notification outbox record could not be persisted.');
            }

            if (
                $record->status === 'pending'
                && (
                    $record->last_dispatched_at === null
                    || now()->subMinutes(5)->greaterThanOrEqualTo($record->last_dispatched_at)
                )
            ) {
                DB::table('notification_outbox')
                    ->where('id', $record->id)
                    ->update(['last_dispatched_at' => now(), 'updated_at' => now()]);

                $id = (string) $record->id;
                DB::afterCommit(fn () => $this->pushReserved($id));
            }

            return (string) $record->id;
        });
    }

    private function pushReserved(string $outboxId): void
    {
        try {
            Queue::pushOn('mail', new DeliverNotificationOutboxJob($outboxId));
        } catch (Throwable $exception) {
            DB::table('notification_outbox')
                ->where('id', $outboxId)
                ->where('status', 'pending')
                ->update(['last_dispatched_at' => null, 'updated_at' => now()]);

            Log::warning('RAVEN23 notification outbox dispatch deferred', [
                'exception_type' => get_class($exception),
            ]);
        }
    }
}
