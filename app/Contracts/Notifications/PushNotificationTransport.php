<?php

namespace App\Contracts\Notifications;

use App\Models\UserPushSubscription;

interface PushNotificationTransport
{
    /**
     * Deliver to one account-owned installation.
     *
     * Provider adapters must use the stable idempotency key with a provider-native
     * deduplication facility when one is available. Tokens remain encrypted at rest.
     */
    public function deliver(
        UserPushSubscription $subscription,
        array $message,
        string $idempotencyKey,
    ): void;
}
