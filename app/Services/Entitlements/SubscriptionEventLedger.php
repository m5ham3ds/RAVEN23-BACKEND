<?php

namespace App\Services\Entitlements;

use App\Exceptions\SubscriptionEventConflict;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionEventLedger
{
    private const EVENT_TYPES = [
        'granted',
        'revoked',
        'expired',
        'refunded',
        'corrected',
    ];

    private const SOURCES = [
        'internal',
        'coupon',
        'payment',
        'administrative',
    ];

    /**
     * Append a trusted domain event. There is intentionally no HTTP endpoint
     * for this service: callers must already have established authority.
     */
    public function record(
        User $user,
        string $eventType,
        string $entitlementCode,
        string $source,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        ?DateTimeInterface $startsAt = null,
        ?DateTimeInterface $expiresAt = null,
        ?string $sourceReference = null,
        ?string $reasonCode = null,
    ): SubscriptionEvent {
        $this->validate($eventType, $entitlementCode, $source, $idempotencyKey, $startsAt, $expiresAt, $sourceReference, $reasonCode);

        $connection = (string) ($user->getConnectionName() ?: config('database.default'));
        $occurred = $this->utc($occurredAt);
        $starts = $startsAt === null ? null : $this->utc($startsAt);
        $expires = $expiresAt === null ? null : $this->utc($expiresAt);

        $fingerprintData = [
            'user_id' => (int) $user->getKey(),
            'event_type' => $eventType,
            'entitlement_code' => $entitlementCode,
            'source' => $source,
            'source_reference' => $sourceReference,
            'idempotency_key' => $idempotencyKey,
            'reason_code' => $reasonCode,
            'occurred_at' => $occurred,
            'starts_at' => $starts,
            'expires_at' => $expires,
        ];
        $eventHash = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));

        return DB::connection($connection)->transaction(function () use (
            $connection,
            $user,
            $eventType,
            $entitlementCode,
            $source,
            $idempotencyKey,
            $sourceReference,
            $reasonCode,
            $occurred,
            $starts,
            $expires,
            $eventHash,
        ): SubscriptionEvent {
            $lockedUser = User::on($connection)
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SubscriptionEvent::on($connection)
                ->where('source', $source)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if (
                    (int) $existing->user_id !== (int) $lockedUser->getKey()
                    || ! hash_equals((string) $existing->event_hash, $eventHash)
                ) {
                    throw new SubscriptionEventConflict('The idempotency key is already bound to a different subscription event.');
                }

                return $existing;
            }

            return SubscriptionEvent::on($connection)->create([
                'user_id' => $lockedUser->getKey(),
                'event_type' => $eventType,
                'entitlement_code' => $entitlementCode,
                'source' => $source,
                'source_reference' => $sourceReference,
                'idempotency_key' => $idempotencyKey,
                'reason_code' => $reasonCode,
                'occurred_at' => $occurred,
                'starts_at' => $starts,
                'expires_at' => $expires,
                'event_hash' => $eventHash,
                'recorded_at' => now('UTC'),
            ]);
        });
    }

    private function validate(
        string $eventType,
        string $entitlementCode,
        string $source,
        string $idempotencyKey,
        ?DateTimeInterface $startsAt,
        ?DateTimeInterface $expiresAt,
        ?string $sourceReference,
        ?string $reasonCode,
    ): void {
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported subscription event type.');
        }

        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unsupported subscription event source.');
        }

        if (! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/', $entitlementCode)) {
            throw new InvalidArgumentException('Invalid entitlement code.');
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid subscription event idempotency key.');
        }

        if ($sourceReference !== null && (strlen($sourceReference) > 128 || trim($sourceReference) === '')) {
            throw new InvalidArgumentException('Invalid subscription event source reference.');
        }

        if ($reasonCode !== null && ! preg_match('/^[a-z0-9_.-]{1,80}$/', $reasonCode)) {
            throw new InvalidArgumentException('Invalid subscription event reason code.');
        }

        if ($startsAt !== null && $expiresAt !== null && $expiresAt <= $startsAt) {
            throw new InvalidArgumentException('An entitlement expiry must follow its start time.');
        }
    }

    private function utc(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s.u');
    }
}
