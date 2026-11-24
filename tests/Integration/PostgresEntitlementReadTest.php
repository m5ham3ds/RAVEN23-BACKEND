<?php

namespace Tests\Integration;

use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresEntitlementReadTest extends TestCase
{
    private array $createdUsers = [];

    private array $createdTokens = [];

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

    private function createAccount(string $label): array
    {
        $suffix = bin2hex(random_bytes(6));
        $user = User::create([
            'name' => 'RAVEN23 PostgreSQL entitlement '.$label,
            'email' => 'raven-entitlement-'.$suffix.'@example.test',
            'username' => 'ent_pg_'.$suffix,
            'password' => 'Raven-Entitlement-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL entitlement '.$label)->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);

        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        if ($this->createdTokens !== []) {
            DB::table('personal_access_tokens')->whereIn('id', $this->createdTokens)->delete();
        }
        if ($this->createdUsers !== []) {
            DB::table('users')->whereIn('id', $this->createdUsers)->delete();
        }

        parent::tearDown();
    }

    public function test_postgresql_entitlements_are_time_bounded_revocable_and_owner_scoped(): void
    {
        $owner = $this->createAccount('owner');
        $other = $this->createAccount('other');
        $expired = $this->createAccount('expired');
        $revoked = $this->createAccount('revoked');

        UserEntitlement::create([
            'user_id' => $owner['id'],
            'code' => 'ad_free',
            'starts_at' => now('UTC')->subDay(),
            'expires_at' => now('UTC')->addDay(),
            'revision' => 9,
            'source' => 'integration_fixture',
        ]);
        UserEntitlement::create([
            'user_id' => $expired['id'],
            'code' => 'ad_free',
            'starts_at' => now('UTC')->subDays(3),
            'expires_at' => now('UTC')->subDay(),
            'revision' => 2,
            'source' => 'integration_fixture',
        ]);
        UserEntitlement::create([
            'user_id' => $revoked['id'],
            'code' => 'ad_free',
            'starts_at' => now('UTC')->subDay(),
            'expires_at' => now('UTC')->addDay(),
            'revoked_at' => now('UTC')->subHour(),
            'revision' => 3,
            'source' => 'integration_fixture',
        ]);

        $this->withToken($owner['token'])
            ->getJson('/api/v1/user/entitlements?user_id='.$other['id'])
            ->assertOk()
            ->assertJsonPath('entitlements.ad_free', true)
            ->assertJsonPath('entitlements.revision', 9);

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->getJson('/api/v1/user/entitlements')
            ->assertOk()
            ->assertJsonPath('entitlements.ad_free', false);

        Auth::forgetGuards();
        $this->withToken($expired['token'])
            ->getJson('/api/v1/user/entitlements')
            ->assertOk()
            ->assertJsonPath('entitlements.ad_free', false);

        Auth::forgetGuards();
        $this->withToken($revoked['token'])
            ->getJson('/api/v1/user/entitlements')
            ->assertOk()
            ->assertJsonPath('entitlements.ad_free', false);
    }
}
