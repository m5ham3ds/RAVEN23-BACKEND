<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\PushNotificationTransport;
use App\Models\UserPushSubscription;
use RuntimeException;

final class DisabledPushNotificationTransport implements PushNotificationTransport
{
    public function deliver(
        UserPushSubscription $subscription,
        array $message,
        string $idempotencyKey,
    ): void {
        throw new RuntimeException('Push delivery is disabled until an approved provider adapter is configured.');
    }
}
