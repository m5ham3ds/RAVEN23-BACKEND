<?php

namespace App\Jobs;

use App\Contracts\Notifications\NotificationOutboxTransport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliverNotificationOutboxJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    private const CLAIM_LEASE_SECONDS = 60;

    public function __construct(public readonly string $outboxId)
    {
        $this->onQueue('mail');
    }

    public function backoff(): array
    {
        return [10, 30, 90, 180];
    }

    public function handle(NotificationOutboxTransport $transport): void
    {
        $claim = DB::transaction(function (): array {
            $record = DB::table('notification_outbox')
                ->where('id', $this->outboxId)
                ->lockForUpdate()
                ->first();

            if ($record === null || in_array($record->status, ['sent', 'failed'], true)) {
                return ['action' => 'skip'];
            }

            if (now()->lessThan($record->available_at)) {
                return [
                    'action' => 'release',
                    'seconds' => max(1, now()->diffInSeconds($record->available_at, false)),
                ];
            }

            if (
                $record->status === 'processing'
                && $record->claimed_at !== null
                && now()->diffInSeconds($record->claimed_at) < self::CLAIM_LEASE_SECONDS
            ) {
                $age = max(0, now()->diffInSeconds($record->claimed_at));

                return [
                    'action' => 'release',
                    'seconds' => max(1, self::CLAIM_LEASE_SECONDS - $age),
                ];
            }

            DB::table('notification_outbox')
                ->where('id', $record->id)
                ->update([
                    'status' => 'processing',
                    'claimed_at' => now(),
                    'attempts' => ((int) $record->attempts) + 1,
                    'updated_at' => now(),
                ]);

            return ['action' => 'send', 'record' => $record];
        });

        if ($claim['action'] === 'skip') {
            return;
        }

        if ($claim['action'] === 'release') {
            $this->release((int) $claim['seconds']);

            return;
        }

        $record = $claim['record'];

        try {
            $user = User::query()->find((int) $record->user_id);
            if ($user === null) {
                DB::table('notification_outbox')->where('id', $this->outboxId)->delete();

                return;
            }

            $payload = json_decode(
                Crypt::decryptString((string) $record->encrypted_payload),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $transport->deliver(
                $user,
                (string) $record->notification_type,
                $payload,
                (string) $record->idempotency_key,
            );

            DB::table('notification_outbox')
                ->where('id', $this->outboxId)
                ->where('status', 'processing')
                ->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'claimed_at' => null,
                    'last_error_type' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            $attempt = ((int) $record->attempts) + 1;
            $delays = $this->backoff();
            $delay = $delays[min($attempt - 1, count($delays) - 1)];

            DB::table('notification_outbox')
                ->where('id', $this->outboxId)
                ->where('status', 'processing')
                ->update([
                    'status' => 'pending',
                    'claimed_at' => null,
                    'available_at' => now()->addSeconds($delay),
                    'last_error_type' => get_class($exception),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('notification_outbox')
            ->where('id', $this->outboxId)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'failed',
                'claimed_at' => null,
                'last_error_type' => $exception ? get_class($exception) : 'unknown',
                'updated_at' => now(),
            ]);
    }
}
