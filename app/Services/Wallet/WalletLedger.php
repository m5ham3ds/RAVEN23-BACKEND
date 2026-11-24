<?php

namespace App\Services\Wallet;

use App\Exceptions\WalletTransactionConflict;
use App\Models\User;
use App\Models\UserWallet;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WalletLedger
{
    private const SOURCES = [
        'internal',
        'coupon',
        'payment',
        'administrative',
    ];

    private const TYPES = [
        'topup',
        'plus_period',
        'refund',
        'coupon_credit',
        'manual_adjustment',
    ];

    /**
     * Credit is an internal domain operation only. There is intentionally no
     * public route that lets a client create wallet balance.
     */
    public function credit(
        User $user,
        int $amountCents,
        string $type,
        string $source,
        string $idempotencyKey,
        ?string $reference = null,
        ?DateTimeInterface $periodStartsAt = null,
        ?DateTimeInterface $periodEndsAt = null,
    ): WalletTransaction {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Wallet credit amount must be positive.');
        }

        return $this->apply(
            $user,
            $amountCents,
            $type,
            $source,
            $idempotencyKey,
            $reference,
            $periodStartsAt,
            $periodEndsAt,
        );
    }

    /**
     * Debit is an internal domain operation and cannot make the balance
     * negative or spend frozen/unavailable credit.
     */
    public function debit(
        User $user,
        int $amountCents,
        string $type,
        string $source,
        string $idempotencyKey,
        ?string $reference = null,
        ?DateTimeInterface $periodStartsAt = null,
        ?DateTimeInterface $periodEndsAt = null,
    ): WalletTransaction {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Wallet debit amount must be positive.');
        }

        return $this->apply(
            $user,
            -$amountCents,
            $type,
            $source,
            $idempotencyKey,
            $reference,
            $periodStartsAt,
            $periodEndsAt,
        );
    }

    private function apply(
        User $user,
        int $deltaCents,
        string $type,
        string $source,
        string $idempotencyKey,
        ?string $reference,
        ?DateTimeInterface $periodStartsAt,
        ?DateTimeInterface $periodEndsAt,
    ): WalletTransaction {
        $this->validate(
            $deltaCents,
            $type,
            $source,
            $idempotencyKey,
            $reference,
            $periodStartsAt,
            $periodEndsAt,
        );

        $connection = (string) ($user->getConnectionName() ?: config('database.default'));
        $startsAt = $periodStartsAt === null ? null : $this->utc($periodStartsAt);
        $endsAt = $periodEndsAt === null ? null : $this->utc($periodEndsAt);

        return DB::connection($connection)->transaction(function () use (
            $connection,
            $user,
            $deltaCents,
            $type,
            $source,
            $idempotencyKey,
            $reference,
            $startsAt,
            $endsAt,
        ): WalletTransaction {
            // Account lock serializes wallet creation/ledger writes with
            // account deletion, avoiding a credit-vs-delete race.
            $owner = User::on($connection)
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $wallet = UserWallet::on($connection)
                ->where('user_id', $owner->getKey())
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                $wallet = UserWallet::on($connection)->create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $owner->getKey(),
                    'currency' => 'USD',
                    'balance_cents' => 0,
                    'spendable_cents' => 0,
                    'status' => 'active',
                    'frozen' => false,
                    'revision' => 0,
                ]);
            }

            $payloadHash = hash('sha256', json_encode([
                'user_id' => (int) $owner->getKey(),
                'wallet_id' => (string) $wallet->getKey(),
                'delta_cents' => $deltaCents,
                'type' => $type,
                'source' => $source,
                'reference' => $reference,
                'period_starts_at' => $startsAt,
                'period_ends_at' => $endsAt,
                'idempotency_key' => $idempotencyKey,
            ], JSON_THROW_ON_ERROR));

            $existing = WalletTransaction::on($connection)
                ->where('wallet_id', $wallet->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new WalletTransactionConflict('The wallet idempotency key is already bound to a different transaction.');
                }

                return $existing;
            }

            if ($deltaCents < 0 && ($wallet->frozen || $wallet->status !== 'active')) {
                throw new DomainException('The wallet is not available for spending.');
            }

            $nextBalance = (int) $wallet->balance_cents + $deltaCents;
            $nextSpendable = (int) $wallet->spendable_cents + $deltaCents;

            if ($nextBalance < 0 || $nextSpendable < 0) {
                throw new DomainException('The wallet has insufficient spendable balance.');
            }

            $wallet->forceFill([
                'balance_cents' => $nextBalance,
                'spendable_cents' => $nextSpendable,
                'revision' => (int) $wallet->revision + 1,
            ])->save();

            return WalletTransaction::on($connection)->create([
                'id' => (string) Str::uuid(),
                'wallet_id' => $wallet->getKey(),
                'type' => $type,
                'source' => $source,
                'amount_cents' => $deltaCents,
                'balance_after_cents' => $nextBalance,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'period_starts_at' => $startsAt,
                'period_ends_at' => $endsAt,
                'recorded_at' => now('UTC'),
            ]);
        });
    }

    private function validate(
        int $deltaCents,
        string $type,
        string $source,
        string $idempotencyKey,
        ?string $reference,
        ?DateTimeInterface $periodStartsAt,
        ?DateTimeInterface $periodEndsAt,
    ): void {
        if ($deltaCents === 0 || $deltaCents === PHP_INT_MIN) {
            throw new InvalidArgumentException('Wallet transaction amount is outside the supported range.');
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported wallet transaction type.');
        }

        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unsupported wallet transaction source.');
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid wallet transaction idempotency key.');
        }

        if ($reference !== null && (trim($reference) === '' || strlen($reference) > 128)) {
            throw new InvalidArgumentException('Invalid wallet transaction reference.');
        }

        if (
            $periodStartsAt !== null
            && $periodEndsAt !== null
            && $periodEndsAt <= $periodStartsAt
        ) {
            throw new InvalidArgumentException('Wallet transaction period end must follow its start.');
        }
    }

    private function utc(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s.u');
    }
}
