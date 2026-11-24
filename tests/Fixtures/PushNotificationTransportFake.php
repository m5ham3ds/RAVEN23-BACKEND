<?php

namespace Tests\Fixtures;

use App\Contracts\Notifications\PushNotificationTransport;
use App\Models\UserPushSubscription;
use RuntimeException;

class PushNotificationTransportFake implements PushNotificationTransport
{
    public array $calls = [];

    public array $accepted = [];

    public bool $crashAfterFirstAcceptance = false;

    public function deliver(
        UserPushSubscription $subscription,
        array $message,
        string $idempotencyKey,
    ): void {
        $this->calls[] = [
            'subscription_id' => (int) $subscription->getKey(),
            'user_id' => (int) $subscription->user_id,
            'provider' => (string) $subscription->provider,
            'token' => (string) $subscription->token,
            'message' => $message,
            'idempotency_key' => $idempotencyKey,
        ];

        if (! isset($this->accepted[$idempotencyKey])) {
            $this->accepted[$idempotencyKey] = true;
        }

        if ($this->crashAfterFirstAcceptance) {
            $this->crashAfterFirstAcceptance = false;
            throw new RuntimeException('synthetic push transport crash after acceptance');
        }
    }
}
