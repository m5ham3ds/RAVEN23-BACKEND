<?php

namespace Tests\Integration;

use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresExpireDueEntitlementsTest extends TestCase
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
            'name' => 'RAVEN23 PostgreSQL expiry',
            'email' => 'raven-expiry-'.$suffix.'@example.test',
            'username' => 'expiry_pg_'.$suffix,
            'password' => 'Raven-Expiry-Postgres-42!',
        ]);
        $this->createdUsers[] = (int) $user->getKey();

        return $user;
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        if ($this->createdUsers !== []) {
            DB::table('users')->whereIn('id', $this->createdUsers)->delete();
        }

        parent::tearDown();
    }

    public function test_postgresql_expiry_command_uses_ledger_and_is_repeat_safe(): void
    {
        $user = $this->createAccount();
        $started = now('UTC')->subDays(10);
        $expiresAt = now('UTC')->subDay();

        $entitlement = UserEntitlement::create([
            'user_id' => $user->getKey(),
            'code' => 'ad_free',
            'starts_at' => $started,
            'expires_at' => $expiresAt,
            'revision' => 1,
            'source' => 'internal',
            'source_reference' => 'integration-expiry',
        ]);

        $this->artisan('subscriptions:expire-due', ['--apply' => true])
            ->expectsOutputToContain('applied=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('subscription_events', [
            'user_id' => $user->getKey(),
            'event_type' => 'expired',
            'projection_status' => 'applied',
        ]);
        $this->assertDatabaseHas('user_entitlements', [
            'id' => $entitlement->getKey(),
            'revoked_at' => $expiresAt->toDateTimeString(),
            'revision' => 2,
        ]);

        $this->artisan('subscriptions:expire-due', ['--apply' => true])
            ->expectsOutputToContain('candidates=0')
            ->assertExitCode(0);

        $this->assertDatabaseCount('subscription_events', 1);
    }
}
