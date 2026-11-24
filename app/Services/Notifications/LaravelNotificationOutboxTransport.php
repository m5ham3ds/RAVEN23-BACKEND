<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationOutboxTransport;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

final class LaravelNotificationOutboxTransport implements NotificationOutboxTransport
{
    public function deliver(User $user, string $notificationType, array $payload, string $idempotencyKey): void
    {
        if (! isset($payload['token']) || (string) $payload['token'] === '') {
            throw new RuntimeException('The encrypted notification payload is incomplete.');
        }

        $notification = match ($notificationType) {
            'email_verification' => new EmailVerificationNotification(
                (int) $user->getKey(),
                (string) $payload['token'],
                (int) ($payload['expires_in_minutes'] ?? 60),
                $idempotencyKey,
            ),
            'password_reset' => new PasswordResetNotification((string) $payload['token'], $idempotencyKey),
            default => throw new RuntimeException('Unsupported notification outbox type.'),
        };

        // sendNow intentionally bypasses ShouldQueue: the outbox job is the sole
        // delivery unit, preventing a second hidden queue layer.
        Notification::sendNow($user, $notification);
    }
}
