<?php

namespace Tests\Fixtures;

use App\Contracts\Notifications\NotificationOutboxTransport;
use App\Models\User;
use RuntimeException;

class TransientFailingNotificationOutboxTransportFake implements NotificationOutboxTransport
{
    public array $calls = [];

    public function deliver(User $user, string $notificationType, array $payload, string $idempotencyKey): void
    {
        $this->calls[] = [
            'user_id' => (int) $user->getKey(),
            'type' => $notificationType,
            'idempotency_key' => $idempotencyKey,
        ];

        throw new RuntimeException('synthetic notification transport unavailable');
    }
}
