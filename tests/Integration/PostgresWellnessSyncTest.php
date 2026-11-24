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
            'username' => 'well_'.$suffix,
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
            'startedAtUtc' => 1_795_392_000_000,
            'endedAtUtc' => 1_795_392_060_000,
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

    public function test_postgresql_daily_aggregates_converge_for_two_devices_out_of_order(): void
    {
        $owner = $this->createAccount();
        $secondToken = User::query()
            ->findOrFail($owner['id'])
            ->createToken('PostgreSQL wellness second device')->plainTextToken;
        $secondStored = PersonalAccessToken::findToken($secondToken);
        $this->createdTokens[] = (int) $secondStored->getKey();

        $newer = $this->wellnessSession(
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            2000,
        );
        $newer['deviceId'] = 'postgres-device-a';
        $dailyA = [
            'local_day' => '2026-11-23',
            'timezone_offset_minutes' => 0,
            'watched_ms' => 60000,
            'movie_ms' => 0,
            'episode_ms' => 60000,
            'live_ms' => 0,
            'completed_movies' => 0,
            'completed_episodes' => 0,
            'session_count' => 1,
            'updated_at_utc' => 2000,
        ];

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$newer],
                'daily' => [$dailyA],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $older = $this->wellnessSession(
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            1000,
        );
        $older['deviceId'] = 'postgres-device-b';
        $staleDaily = $dailyA;
        $staleDaily['updated_at_utc'] = 1000;

        $this->withToken($secondToken)
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$older],
                'daily' => [$staleDaily],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $pull = $this->withToken($secondToken)
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'sessions')
            ->assertJsonCount(1, 'daily')
            ->assertJsonPath('daily.0.watched_ms', 120000)
            ->assertJsonPath('daily.0.movie_ms', 0)
            ->assertJsonPath('daily.0.episode_ms', 120000)
            ->assertJsonPath('daily.0.session_count', 2)
            ->assertJsonPath('daily.0.updated_at_utc', 2000);

        $row = DB::table('user_wellness_daily')
            ->where('user_id', $owner['id'])
            ->where('local_day', '2026-11-23')
            ->first();
        $summary = json_decode((string) $row->payload, true);
        $this->assertSame(120000, $summary['watched_ms']);
        $this->assertSame(2, $summary['session_count']);
        $this->assertGreaterThan(0, (int) $pull->json('server_revision'));
    }


    public function test_postgresql_pagination_keeps_its_snapshot_when_new_writes_commit_between_pages_and_recovers_from_a_corrupt_cursor(): void
    {
        $owner = $this->createAccount();
        $firstId = '11111111-1111-4111-8111-111111111111';
        $secondId = '22222222-2222-4222-8222-222222222222';
        $laterId = '33333333-3333-4333-8333-333333333333';

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [
                    $this->wellnessSession($firstId, 1000),
                    $this->wellnessSession($secondId, 2000),
                ],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3);

        Auth::forgetGuards();
        $first = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('has_more', true)
            ->assertJsonCount(1, 'sessions');

        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        Auth::forgetGuards();
        $later = $this->wellnessSession($laterId, 3000);
        $later['deviceId'] = 'postgres-concurrent-writer';
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$later],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $second = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'cursor' => $cursor,
                'limit' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('has_more', true)
            ->assertJsonCount(1, 'sessions');

        Auth::forgetGuards();
        $third = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'cursor' => $second->json('next_cursor'),
                'limit' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(1, 'daily')
            ->assertJsonPath('daily.0.watched_ms', 120000);

        $snapshotIds = [
            $first->json('sessions.0.id'),
            $second->json('sessions.0.id'),
        ];
        sort($snapshotIds);
        $this->assertSame([$firstId, $secondId], $snapshotIds);

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'cursor' => 'tampered.cursor',
                'limit' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'invalid_sync_cursor');

        Auth::forgetGuards();
        $fullRecovery = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('full', true)
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(3, 'sessions')
            ->assertJsonCount(1, 'daily')
            ->assertJsonPath('daily.0.watched_ms', 180000);

        $recoveredIds = array_column($fullRecovery->json('sessions'), 'id');
        sort($recoveredIds);
        $this->assertSame([$firstId, $secondId, $laterId], $recoveredIds);
        $this->assertGreaterThan(3, (int) $fullRecovery->json('server_revision'));
    }

    public function test_postgresql_wellness_upload_replay_after_lost_response_is_idempotent(): void
    {
        $owner = $this->createAccount();
        $sessionId = '99999999-9999-4999-8999-999999999999';
        $session = $this->wellnessSession($sessionId, 5000);
        $daily = [
            'local_day' => '2026-11-23',
            'timezone_offset_minutes' => 0,
            'watched_ms' => 60000,
            'movie_ms' => 0,
            'episode_ms' => 60000,
            'live_ms' => 0,
            'completed_movies' => 0,
            'completed_episodes' => 0,
            'session_count' => 1,
            'updated_at_utc' => 5000,
        ];
        $payload = [
            'client_time_utc' => $this->clientTime(),
            'sessions' => [$session],
            'daily' => [$daily],
            'limit' => 450,
        ];

        $first = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', $payload)
            ->assertOk()
            ->assertJsonPath('server_revision', 2);

        Auth::forgetGuards();
        // Simulate a request whose response was lost: the next sync cycle
        // replays the persisted upload with the exact same entity payloads.
        $retry = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', $payload)
            ->assertOk()
            ->assertJsonPath('server_revision', 2);

        $this->assertSame($first->json('server_revision'), $retry->json('server_revision'));
        $this->assertDatabaseCount('user_wellness_sessions', 1);
        $this->assertDatabaseCount('user_wellness_daily', 1);
        $this->assertDatabaseCount('user_wellness_changes', 2);
        $this->assertDatabaseCount('user_wellness_upload_receipts', 2);
        $this->assertDatabaseHas('user_sync_revisions', [
            'user_id' => $owner['id'],
            'domain' => 'wellness',
            'revision' => 2,
        ]);
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
