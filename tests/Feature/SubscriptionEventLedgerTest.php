<?php

namespace Tests\Feature;

use App\Exceptions\SubscriptionEventConflict;
use App\Models\User;
use App\Services\Entitlements\SubscriptionEventLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionEventLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    private function account(): User
    {
        $suffix = bin2hex(random_bytes(5));

        return User::create([
            'name' => 'RAVEN23 Ledger Test',
            'email' => 'ledger-'.$suffix.'@example.test',
            'username' => 'ledger_'.$suffix,
            'password' => 'Raven-Ledger-Test-Password-42!',
        ]);
    }

    public function test_records_a_trusted_event_once_for_repeated_idempotency_key(): void
    {
        $user = $this->account();
        $ledger = app(SubscriptionEventLedger::class);
        $occurredAt = now('UTC')->subMinute();
        $expiresAt = now('UTC')->addDays(30);

        $first = $ledger->record(
            $user,
            'granted',
            'ad_free',
            'internal',
            'event-key-0001',
            $occurredAt,
            $occurredAt,
            $expiresAt,
            'support-ticket-123',
            'courtesy_period',
        );
        $again = $ledger->record(
            $user,
            'granted',
            'ad_free',
            'internal',
            'event-key-0001',
            $occurredAt,
            $occurredAt,
            $expiresAt,
            'support-ticket-123',
            'courtesy_period',
        );

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertDatabaseCount('subscription_events', 1);
        $this->assertSame((int) $user->getKey(), (int) $first->user_id);
        $this->assertSame('ad_free', $first->entitlement_code);
        $this->assertSame(64, strlen($first->event_hash));
        $this->assertNull($first->updated_at);
    }

    public function test_reusing_an_idempotency_key_with_different_event_content_conflicts(): void
    {
        $user = $this->account();
        $ledger = app(SubscriptionEventLedger::class);
        $at = now('UTC');

        $ledger->record($user, 'granted', 'ad_free', 'coupon', 'event-key-0002', $at, $at);

        $this->expectException(SubscriptionEventConflict::class);
        $ledger->record($user, 'revoked', 'ad_free', 'coupon', 'event-key-0002', $at);
    }

    public function test_raw_update_and_direct_delete_are_rejected_by_database_guards(): void
    {
        $user = $this->account();
        $event = app(SubscriptionEventLedger::class)->record(
            $user,
            'granted',
            'ad_free',
            'internal',
            'event-key-0003',
            now('UTC'),
            now('UTC'),
        );

        try {
            DB::table('subscription_events')->where('id', $event->getKey())->update(['reason_code' => 'tampered']);
            $this->fail('An append-only subscription event accepted an UPDATE.');
        } catch (QueryException) {
            $this->assertDatabaseHas('subscription_events', ['id' => $event->getKey(), 'reason_code' => null]);
        }

        try {
            DB::table('subscription_events')->where('id', $event->getKey())->delete();
            $this->fail('An append-only subscription event accepted a direct DELETE.');
        } catch (QueryException) {
            $this->assertDatabaseHas('subscription_events', ['id' => $event->getKey()]);
        }
    }

    public function test_user_deletion_cascades_its_event_history_for_account_erasure(): void
    {
        $user = $this->account();
        $event = app(SubscriptionEventLedger::class)->record(
            $user,
            'granted',
            'ad_free',
            'administrative',
            'event-key-0004',
            now('UTC'),
        );

        $user->delete();

        $this->assertDatabaseMissing('subscription_events', ['id' => $event->getKey()]);
        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    }

    public function test_unknown_source_and_invalid_expiry_are_rejected(): void
    {
        $user = $this->account();
        $ledger = app(SubscriptionEventLedger::class);
        $at = now('UTC');

        try {
            $ledger->record($user, 'granted', 'ad_free', 'client', 'event-key-0005', $at);
            $this->fail('An untrusted client source was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Unsupported subscription event source.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $ledger->record($user, 'granted', 'ad_free', 'internal', 'event-key-0006', $at, $at->copy()->addDay(), $at);
    }
}
