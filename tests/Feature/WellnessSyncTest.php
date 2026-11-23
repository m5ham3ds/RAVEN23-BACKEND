<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WellnessSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'cache.default' => 'array',
            'mail.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function registerAccount(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN23 Wellness Test',
            'email' => 'wellness-'.$suffix.'@example.test',
            'username' => 'wellness_'.$suffix,
            'password' => 'Raven-Wellness-Test-Password-42!',
        ])->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
        ];
    }

    private function clientTime(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function createSecondDeviceToken(int $userId): string
    {
        return User::query()
            ->findOrFail($userId)
            ->createToken('wellness-second-device')
            ->plainTextToken;
    }

    private function wellnessSession(
        string $id,
        int $stamp,
        ?int $deleted = null,
        string $title = 'Fight Club',
    ): array {
        return [
            'id' => $id,
            'deviceId' => 'android-device-1',
            'mediaType' => 'movie',
            'source' => 'streaming',
            'contentId' => '550',
            'title' => $title,
            'startedAtUtc' => 1_795_392_000_000,
            'endedAtUtc' => 1_795_392_060_000,
            'timezoneOffsetMinutes' => 120,
            'watchedMs' => 60_000,
            'durationMs' => 120_000,
            'progressEndMs' => 60_000,
            'completed' => false,
            'segments' => [
                [
                    'startedAtUtc' => 1_795_392_000_000,
                    'endedAtUtc' => 1_795_392_060_000,
                ],
            ],
            'genres' => ['Drama'],
            'languages' => ['en'],
            'countries' => ['US'],
            'networkBytes' => 12345,
            'updated_at_utc' => $stamp,
            'deleted_at_utc' => $deleted,
        ];
    }

    private function daily(string $day, int $stamp, int $watched = 60_000): array
    {
        return [
            'local_day' => $day,
            'timezone_offset_minutes' => 120,
            'watched_ms' => $watched,
            'movie_ms' => $watched,
            'episode_ms' => 0,
            'live_ms' => 0,
            'completed_movies' => 0,
            'completed_episodes' => 0,
            'session_count' => $watched === 0 ? 0 : 1,
            'updated_at_utc' => $stamp,
        ];
    }

    private function upload(string $token, array $sessions = [], array $daily = []): void
    {
        $this->withToken($token)
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => $sessions,
                'daily' => $daily,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['server_revision', 'server_time_utc']);
    }

    public function test_upload_batches_and_full_pull_round_trip_sessions_and_daily_summaries(): void
    {
        $account = $this->registerAccount();
        $sessionId = '11111111-1111-4111-8111-111111111111';

        $this->upload(
            $account['token'],
            [$this->wellnessSession($sessionId, 1000)],
            [$this->daily('2026-11-23', 1100)],
        );

        Auth::forgetGuards();
        $pull = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('full', true)
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonCount(1, 'daily')
            ->assertJsonPath('sessions.0.id', $sessionId)
            ->assertJsonPath('sessions.0.network_bytes', 12345)
            ->assertJsonPath('sessions.0.updated_at_utc', 1000)
            ->assertJsonPath('sessions.0.deleted', false)
            ->assertJsonPath('daily.0.local_day', '2026-11-23')
            ->assertJsonPath('daily.0.watched_ms', 60000);

        $this->assertDatabaseHas('user_wellness_sessions', [
            'user_id' => $account['id'],
            'session_id' => $sessionId,
            'updated_at_utc' => 1000,
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('user_wellness_daily', [
            'user_id' => $account['id'],
            'local_day' => '2026-11-23',
            'updated_at_utc' => 1000,
            'revision' => 2,
        ]);
        $this->assertSame(2, $pull->json('server_revision'));
    }

    public function test_stable_cursor_snapshot_excludes_changes_committed_after_the_first_page(): void
    {
        $account = $this->registerAccount();
        $firstId = '11111111-1111-4111-8111-111111111111';
        $secondId = '22222222-2222-4222-8222-222222222222';
        $laterId = '33333333-3333-4333-8333-333333333333';

        $this->upload($account['token'], [
            $this->wellnessSession($firstId, 1000),
            $this->wellnessSession($secondId, 2000),
        ]);

        Auth::forgetGuards();
        $first = $this->withToken($account['token'])
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
        $this->upload($account['token'], [$this->wellnessSession($laterId, 3000)]);

        Auth::forgetGuards();
        $second = $this->withToken($account['token'])
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
        $third = $this->withToken($account['token'])
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
            ->assertJsonCount(1, 'daily');

        $snapshotIds = [
            $first->json('sessions.0.id'),
            $second->json('sessions.0.id'),
        ];
        sort($snapshotIds);
        $this->assertSame([$firstId, $secondId], $snapshotIds);
        $this->assertSame('2026-11-23', $third->json('daily.0.local_day'));
        $this->assertSame(120000, $third->json('daily.0.watched_ms'));

        Auth::forgetGuards();
        $incremental = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 3,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 5)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonCount(1, 'daily')
            ->assertJsonPath('sessions.0.id', $laterId)
            ->assertJsonPath('daily.0.watched_ms', 180000);
    }

    public function test_tombstones_win_over_stale_devices_and_exact_upload_replays_are_idempotent(): void
    {
        $account = $this->registerAccount();
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $this->upload($account['token'], [$this->wellnessSession($id, 1000)]);

        Auth::forgetGuards();
        $this->upload($account['token'], [$this->wellnessSession($id, 2000, 2000)]);

        Auth::forgetGuards();
        $this->upload($account['token'], [$this->wellnessSession($id, 1500, null, 'Old device copy')]);

        Auth::forgetGuards();
        $this->upload($account['token'], [$this->wellnessSession($id, 1500, null, 'Old device copy')]);

        $this->assertDatabaseHas('user_sync_revisions', [
            'user_id' => $account['id'],
            'domain' => 'wellness',
            'revision' => 5,
        ]);
        $this->assertDatabaseHas('user_wellness_upload_receipts', [
            'user_id' => $account['id'],
            'entity_type' => 'session',
            'entity_key' => $id,
            'upload_stamp' => 1500,
        ]);

        Auth::forgetGuards();
        $tombstone = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 5)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.deleted_at_utc', 2000)
            ->assertJsonPath('sessions.0.deleted', true);

        Auth::forgetGuards();
        $this->upload($account['token'], [$this->wellnessSession($id, 3000, 2000)]);

        Auth::forgetGuards();
        $resumed = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 3,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 7)
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.deleted_at_utc', null)
            ->assertJsonPath('sessions.0.updated_at_utc', 3000)
            ->assertJsonPath('sessions.0.deleted', false);

        $this->assertSame(5, $tombstone->json('server_revision'));
        $this->assertSame(7, $resumed->json('server_revision'));
        $this->assertDatabaseHas('user_wellness_sessions', [
            'user_id' => $account['id'],
            'session_id' => $id,
            'updated_at_utc' => 3000,
            'deleted_at_utc' => null,
            'revision' => 6,
        ]);
    }

    public function test_two_devices_converge_daily_totals_when_an_older_session_arrives_later(): void
    {
        $account = $this->registerAccount();
        $deviceB = $this->createSecondDeviceToken($account['id']);
        $newerId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $olderId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

        $this->upload(
            $account['token'],
            [$this->wellnessSession($newerId, 2000)],
            [$this->daily('2026-11-23', 2000, 60000)],
        );

        Auth::forgetGuards();
        $this->upload(
            $deviceB,
            [$this->wellnessSession($olderId, 1000)],
            [$this->daily('2026-11-23', 1000, 60000)],
        );

        Auth::forgetGuards();
        $merged = $this->withToken($deviceB)
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'sessions')
            ->assertJsonCount(1, 'daily')
            ->assertJsonPath('daily.0.local_day', '2026-11-23')
            ->assertJsonPath('daily.0.watched_ms', 120000)
            ->assertJsonPath('daily.0.movie_ms', 120000)
            ->assertJsonPath('daily.0.session_count', 2)
            ->assertJsonPath('daily.0.updated_at_utc', 2000);

        $stored = DB::table('user_wellness_daily')
            ->where('user_id', $account['id'])
            ->where('local_day', '2026-11-23')
            ->first();
        $aggregate = json_decode((string) $stored->payload, true);
        $this->assertSame(120000, $aggregate['watched_ms']);
        $this->assertSame(2, $aggregate['session_count']);

        Auth::forgetGuards();
        $corrected = $this->daily('2026-11-23', 2000, 120000);
        $corrected['session_count'] = 2;
        $this->upload($deviceB, [], [$corrected]);

        $storedAfterRetry = DB::table('user_wellness_daily')
            ->where('user_id', $account['id'])
            ->where('local_day', '2026-11-23')
            ->first();
        $this->assertSame(
            120000,
            json_decode((string) $storedAfterRetry->payload, true)['watched_ms'],
        );
        $this->assertGreaterThan(0, (int) $merged->json('server_revision'));
    }

    public function test_sync_is_owner_scoped_and_rejects_clock_skew_and_invalid_cursors(): void
    {
        $owner = $this->registerAccount();
        $other = $this->registerAccount();
        $id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $this->upload($owner['token'], [$this->wellnessSession($id, 1000)]);

        Auth::forgetGuards();
        $empty = $this->withToken($other['token'])
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
                'client_time_utc' => $this->clientTime() + 2 * 86_400_000,
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'clock_skew_detected')
            ->assertJsonStructure(['server_time_utc']);

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'cursor' => 'tampered.cursor',
                'limit' => 450,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'invalid_sync_cursor');

        Auth::forgetGuards();
        $this->withToken('invalid-wellness-token')
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [],
                'daily' => [],
                'since_revision' => 0,
                'limit' => 450,
            ])
            ->assertUnauthorized();

        $this->assertSame(0, $empty->json('server_revision'));
        $this->assertDatabaseCount('user_wellness_sessions', 1);
    }
}
