<?php

namespace App\Contracts\Notifications;

use App\Models\User;

interface NotificationOutboxTransport
{
    /**
     * Deliver a supported account notification using its stable idempotency key.
     *
     * Provider adapters must map the key to a provider-native idempotency facility
     * before claiming provider-enforced deduplication.
     */
    public function deliver(User $user, string $notificationType, array $payload, string $idempotencyKey): void;
}
