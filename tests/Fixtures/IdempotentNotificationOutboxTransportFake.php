<?php

namespace Tests\Fixtures;

use App\Contracts\Notifications\NotificationOutboxTransport;
use App\Models\User;
use RuntimeException;

class IdempotentNotificationOutboxTransportFake implements NotificationOutboxTransport
{
    public array $calls = [];

    public array $accepted = [];

    public bool $crashAfterFirstAcceptance = false;

    public function deliver(User $user, string $notificationType, array $payload, string $idempotencyKey): void
    {
        $this->calls[] = [
            'user_id' => (int) $user->getKey(),
            'type' => $notificationType,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
        ];

        if (! isset($this->accepted[$idempotencyKey])) {
            $this->accepted[$idempotencyKey] = true;
        }

        if ($this->crashAfterFirstAcceptance) {
            $this->crashAfterFirstAcceptance = false;
            throw new RuntimeException('synthetic crash after provider acceptance');
        }
    }
}
