<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresWellnessSyncTest extends TestCase
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
            'name' => 'RAVEN23 PostgreSQL Wellness',
            'email' => 'raven-wellness-'.$suffix.'@example.test',
            'username' => 'raven_wellness_'.$suffix,
            'password' => 'Raven-Wellness-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL wellness sync test')->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);
        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    private function clientTime(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function wellnessSession(string $id, int $stamp, ?int $deleted = null): array
    {
        return [
            'id' => $id,
            'deviceId' => 'postgres-device',
            'mediaType' => 'episode',
            'source' => 'offline',
            'contentId' => '62085',
            'seriesId' => '1396',
            'title' => 'Pilot',
            'seasonNumber' => 1,
            'episodeNumber' => 1,
            'startedAtUtc' => 1_700_000_000_000,
            'endedAtUtc' => 1_700_000_060_000,
            'timezoneOffsetMinutes' => 0,
            'watchedMs' => 60_000,
            'durationMs' => 120_000,
            'progressEndMs' => 60_000,
            'completed' => false,
            'segments' => [],
            'networkBytes' => 900,
            'updated_at_utc' => $stamp,
            'deleted_at_utc' => $deleted,
        ];
    }

    public function test_postgresql_wellness_sync_is_owner_scoped_revisioned_and_tombstone_safe(): void
    {
        $owner = $this->createAccount();
        $other = $this->createAccount();
        $id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$this->wellnessSession($id, 1000)],
                'daily' => [[
                    'local_day' => '2026-11-23',
                    'timezone_offset_minutes' => 0,
                    'watched_ms' => 60000,
                    'movie_ms' => 0,
                    'episode_ms' => 60000,
                    'live_ms' => 0,
                    'completed_movies' => 0,
                    'completed_episodes' => 0,
                    'session_count' => 1,
                    'updated_at_utc' => 1100,
                ]],
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2);

        Auth::forgetGuards();
        $ownerPull = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.media_type', 'episode')
            ->assertJsonPath('sessions.0.network_bytes', 900)
            ->assertJsonCount(1, 'daily');

        Auth::forgetGuards();
        $otherPull = $this->withToken($other['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 0)
            ->assertJsonCount(0, 'sessions')
            ->assertJsonCount(0, 'daily');

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$this->wellnessSession($id, 2000, 2000)],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$this->wellnessSession($id, 1500)],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_wellness_sessions', [
            'user_id' => $owner['id'],
            'session_id' => $id,
            'updated_at_utc' => 2000,
            'deleted_at_utc' => 2000,
        ]);
        $this->assertSame(1, DB::table('user_wellness_sessions')->where('user_id', $owner['id'])->count());
        $this->assertSame(0, DB::table('user_wellness_sessions')->where('user_id', $other['id'])->count());
        $this->assertDatabaseCount('user_wellness_upload_receipts', 4);
        $this->assertSame(2, $ownerPull->json('server_revision'));
        $this->assertSame(0, $otherPull->json('server_revision'));
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
                // Foreign keys clean up all synthetic wellness data.
            }
        }
        Auth::forgetGuards();
        parent::tearDown();
    }
}
