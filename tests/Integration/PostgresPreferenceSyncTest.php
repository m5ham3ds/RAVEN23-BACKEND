<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresPreferenceSyncTest extends TestCase
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
        URL::forceRootUrl('http://localhost');
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        DB::connection()->selectOne('select 1');
    }

    private function createAccount(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $user = User::create([
            'name' => 'RAVEN23 PostgreSQL Preference',
            'email' => 'raven-pref-'.$suffix.'@example.test',
            'username' => 'raven_pref_'.$suffix,
            'password' => 'Raven-Preference-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL preference sync test')->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);
        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    private function operation(string $operationId, int $baseRevision, array $settings): array
    {
        return [
            'operation_id' => $operationId,
            'action' => 'preferences.update',
            'base_revision' => $baseRevision,
            ...$settings,
        ];
    }

    public function test_postgresql_preference_sync_is_idempotent_owner_scoped_and_conflict_safe(): void
    {
        $owner = $this->createAccount();
        $other = $this->createAccount();
        $ownerDeviceTwo = User::findOrFail($owner['id'])
            ->createToken('PostgreSQL preference second device')->plainTextToken;
        $stored = PersonalAccessToken::findToken($ownerDeviceTwo);
        $this->createdTokens[] = (int) $stored->getKey();

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 0,
                'operations' => [$this->operation(
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    0,
                    ['locale' => 'ar', 'timezone' => 'Africa/Khartoum'],
                )],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('results.0.status', 'applied');

        Auth::forgetGuards();
        $replay = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 1,
                'operations' => [$this->operation(
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    0,
                    ['locale' => 'ar', 'timezone' => 'Africa/Khartoum'],
                )],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('results.0.status', 'applied');

        Auth::forgetGuards();
        $stale = $this->withToken($ownerDeviceTwo)
            ->postJson('/api/v1/sync/preferences', [
                'since_revision' => 0,
                'operations' => [$this->operation(
                    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    0,
                    ['timezone' => 'Africa/Tripoli'],
                )],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('preferences.timezone', 'Africa/Khartoum');

        Auth::forgetGuards();
        $otherPull = $this->withToken($other['token'])
            ->postJson('/api/v1/sync/preferences', ['since_revision' => 0])
            ->assertOk()
            ->assertJsonPath('server_revision', 0)
            ->assertJsonPath('preferences.locale', null)
            ->assertJsonPath('preferences.timezone', 'UTC');

        $this->assertSame(1, $replay->json('server_revision'));
        $this->assertSame('conflict', $stale->json('results.0.status'));
        $this->assertSame(0, $otherPull->json('server_revision'));
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $owner['id'],
            'locale' => 'ar',
            'timezone' => 'Africa/Khartoum',
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $other['id'],
            'locale' => null,
            'timezone' => 'UTC',
            'revision' => 0,
        ]);
        $this->assertDatabaseCount('user_preference_operations', 2);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTokens) as $tokenId) {
            try {
                DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
            } catch (\Throwable) {
                // Preserve the original assertion failure.
            }
        }
        foreach (array_reverse($this->createdUsers) as $userId) {
            try {
                DB::table('users')->where('id', $userId)->delete();
            } catch (\Throwable) {
                // Foreign keys clean up the synthetic account's data.
            }
        }
        Auth::forgetGuards();
        parent::tearDown();
    }
}
