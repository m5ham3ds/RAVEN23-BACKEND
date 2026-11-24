<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationOutboxTransport;
use App\Contracts\Notifications\PushNotificationTransport;
use App\Models\User;
use App\Models\UserPushSubscription;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

final class LaravelNotificationOutboxTransport implements NotificationOutboxTransport
{
    public function __construct(
        private readonly PushNotificationTransport $pushTransport,
    ) {}

    public function deliver(User $user, string $notificationType, array $payload, string $idempotencyKey): void
    {
        if ($notificationType === 'push_delivery') {
            $this->deliverPush($user, $payload, $idempotencyKey);

            return;
        }

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

    private function deliverPush(User $user, array $payload, string $idempotencyKey): void
    {
        $provider = (string) config('services.raven23_push.provider', 'none');
        if (
            ! (bool) config('services.raven23_push.enabled', false)
            || ! in_array($provider, ['fcm', 'onesignal'], true)
        ) {
            throw new RuntimeException('Push delivery is disabled or has no approved provider.');
        }

        $subscriptionId = (int) ($payload['subscription_id'] ?? 0);
        $message = $payload['message'] ?? null;
        if ($subscriptionId < 1 || ! is_array($message)) {
            throw new RuntimeException('The encrypted push payload is incomplete.');
        }

        $subscription = UserPushSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $provider)
            ->whereKey($subscriptionId)
            ->first();

        // A subscription revoked after enqueue must never receive a delayed push.
        if ($subscription === null) {
            return;
        }

        $this->pushTransport->deliver($subscription, $message, $idempotencyKey);
    }
}
