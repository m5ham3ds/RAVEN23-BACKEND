<?php

namespace Tests\Integration;

use App\Exceptions\SubscriptionEventConflict;
use App\Models\User;
use App\Services\Entitlements\SubscriptionEventLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresSubscriptionEventLedgerTest extends TestCase
{
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $databaseUrl = trim((string) (getenv('NEON_DB_URL') ?: getenv('DB_URL')));
        if ($databaseUrl === '') {
            $this->markTestSkipped('DB_URL is not configured for PostgreSQL integration tests.');
        }

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.url' => $databaseUrl,
            'database.connections.pgsql.host' => null,
            'database.connections.pgsql.port' => null,
            'database.connections.pgsql.database' => null,
            'database.connections.pgsql.username' => null,
            'database.connections.pgsql.password' => null,
            'database.connections.pgsql.sslmode' => 'require',
            'cache.default' => 'array',
            'app.url' => 'http://localhost',
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        DB::connection()->selectOne('select 1');
    }

    private function createAccount(): User
    {
        $suffix = bin2hex(random_bytes(6));
        $user = User::create([
            'name' => 'RAVEN23 PostgreSQL ledger',
            'email' => 'raven-ledger-'.$suffix.'@example.test',
            'username' => 'ledger_pg_'.$suffix,
            'password' => 'Raven-Ledger-Postgres-42!',
        ]);
        $this->createdUsers[] = (int) $user->getKey();

        return $user;
    }

    protected function tearDown(): void
    {
        if ($this->createdUsers !== []) {
            DB::table('users')->whereIn('id', $this->createdUsers)->delete();
        }

        parent::tearDown();
    }

    public function test_postgresql_ledger_is_idempotent_immutable_and_deletion_safe(): void
    {
        $user = $this->createAccount();
        $ledger = app(SubscriptionEventLedger::class);
        $at = now('UTC');
        $expiresAt = $at->copy()->addDays(30);

        $event = $ledger->record(
            $user,
            'granted',
            'ad_free',
            'payment',
            'pg-event-key-0001',
            $at,
            $at,
            $expiresAt,
            'synthetic-event-ref',
            'test_purchase',
        );

        $duplicate = $ledger->record(
            $user,
            'granted',
            'ad_free',
            'payment',
            'pg-event-key-0001',
            $at,
            $at,
            $expiresAt,
            'synthetic-event-ref',
            'test_purchase',
        );
        $this->assertSame($event->getKey(), $duplicate->getKey());

        try {
            DB::table('subscription_events')->where('id', $event->getKey())->update(['reason_code' => 'tampered']);
            $this->fail('PostgreSQL accepted an UPDATE to an append-only event.');
        } catch (QueryException) {
            $this->assertDatabaseHas('subscription_events', ['id' => $event->getKey(), 'reason_code' => 'test_purchase']);
        }

        try {
            DB::table('subscription_events')->where('id', $event->getKey())->delete();
            $this->fail('PostgreSQL accepted a direct DELETE of an append-only event.');
        } catch (QueryException) {
            $this->assertDatabaseHas('subscription_events', ['id' => $event->getKey()]);
        }

        $user->delete();
        $this->assertDatabaseMissing('subscription_events', ['id' => $event->getKey()]);
    }

    public function test_postgresql_event_idempotency_key_cannot_be_rebound(): void
    {
        $user = $this->createAccount();
        $ledger = app(SubscriptionEventLedger::class);
        $at = now('UTC');

        $ledger->record($user, 'granted', 'ad_free', 'internal', 'pg-event-key-0002', $at, $at);

        $this->expectException(SubscriptionEventConflict::class);
        $ledger->record($user, 'revoked', 'ad_free', 'internal', 'pg-event-key-0002', $at);
    }
}
