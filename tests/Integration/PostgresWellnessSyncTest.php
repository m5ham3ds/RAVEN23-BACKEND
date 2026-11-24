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

    private string $databaseUrl = '';

    protected function setUp(): void
    {
        parent::setUp();
        $databaseUrl = trim((string) (getenv('NEON_DB_URL') ?: getenv('DB_URL')));
        $this->databaseUrl = $databaseUrl;
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

    private function createDeviceToken(int $userId, string $label): string
    {
        $user = User::query()->findOrFail($userId);
        $token = $user->createToken('PostgreSQL wellness '.$label)->plainTextToken;
        $stored = PersonalAccessToken::findToken($token);
        $this->createdTokens[] = (int) $stored->getKey();

        return $token;
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

    public function test_postgresql_three_devices_converge_after_conflicting_out_of_order_updates(): void
    {
        $owner = $this->createAccount();
        $deviceB = User::query()->findOrFail($owner['id'])
            ->createToken('PostgreSQL wellness device B')->plainTextToken;
        $this->createdTokens[] = (int) PersonalAccessToken::findToken($deviceB)->getKey();
        $deviceC = User::query()->findOrFail($owner['id'])
            ->createToken('PostgreSQL wellness device C')->plainTextToken;
        $this->createdTokens[] = (int) PersonalAccessToken::findToken($deviceC)->getKey();
        $sessionId = 'abababab-abab-4bab-8bab-abababababab';

        $deviceAUpdate = $this->wellnessSession($sessionId, 3000);
        $deviceAUpdate['deviceId'] = 'postgres-device-a';
        $deviceAUpdate['title'] = 'Device A update';
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$deviceAUpdate],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $staleDeviceBUpdate = $this->wellnessSession($sessionId, 2000);
        $staleDeviceBUpdate['deviceId'] = 'postgres-device-b';
        $staleDeviceBUpdate['title'] = 'Stale device B update';
        $this->withToken($deviceB)
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$staleDeviceBUpdate],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $deviceCDelete = $this->wellnessSession($sessionId, 4000, 4000);
        $deviceCDelete['deviceId'] = 'postgres-device-c';
        $deviceCDelete['title'] = 'Device C deletion';
        $this->withToken($deviceC)
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$deviceCDelete],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $staleDeviceAReplay = $this->wellnessSession($sessionId, 3500);
        $staleDeviceAReplay['deviceId'] = 'postgres-device-a';
        $staleDeviceAReplay['title'] = 'Stale device A replay';
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$staleDeviceAReplay],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        Auth::forgetGuards();
        $deviceBResume = $this->wellnessSession($sessionId, 5000);
        $deviceBResume['deviceId'] = 'postgres-device-b';
        $deviceBResume['title'] = 'Device B resumed';
        $this->withToken($deviceB)
            ->postJson('/api/v1/sync/wellness', [
                'client_time_utc' => $this->clientTime(),
                'sessions' => [$deviceBResume],
                'daily' => [],
                'limit' => 450,
            ])
            ->assertOk();

        foreach ([$owner['token'], $deviceB, $deviceC] as $token) {
            Auth::forgetGuards();
            $pull = $this->withToken($token)
                ->postJson('/api/v1/sync/wellness', [
                    'client_time_utc' => $this->clientTime(),
                    'sessions' => [],
                    'daily' => [],
                    'since_revision' => 0,
                    'limit' => 450,
                ])
                ->assertOk()
                ->assertJsonCount(1, 'sessions')
                ->assertJsonCount(1, 'daily')
                ->assertJsonPath('sessions.0.id', $sessionId)
                ->assertJsonPath('sessions.0.title', 'Device B resumed')
                ->assertJsonPath('sessions.0.updated_at_utc', 5000)
                ->assertJsonPath('sessions.0.deleted', false)
                ->assertJsonPath('daily.0.watched_ms', 60000)
                ->assertJsonPath('daily.0.session_count', 1);

            $this->assertGreaterThan(0, (int) $pull->json('server_revision'));
        }

        $this->assertSame(1, DB::table('user_wellness_sessions')
            ->where('user_id', $owner['id'])
            ->count());
        $this->assertSame(1, DB::table('user_wellness_daily')
            ->where('user_id', $owner['id'])
            ->count());
        $this->assertDatabaseHas('user_wellness_sessions', [
            'user_id' => $owner['id'],
            'session_id' => $sessionId,
            'updated_at_utc' => 5000,
            'deleted_at_utc' => null,
        ]);
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

    public function test_postgresql_parallel_device_uploads_converge_to_the_newest_session_version(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the PostgreSQL concurrency test.');
        }

        $owner = $this->createAccount();
        $tokens = [$owner['token']];
        for ($index = 1; $index < 8; $index++) {
            $tokens[] = $this->createDeviceToken($owner['id'], 'parallel device '.chr(65 + $index));
        }
        $sessionId = 'abababab-abab-4bab-8bab-abababababac';
        $stamps = [3000, 1000, 5000, 2000, 4000, 1500, 3500, 4500];
        $titles = [
            'Device A update',
            'Device B update',
            'Device C newest update',
            'Device D update',
            'Device E update',
            'Device F update',
            'Device G update',
            'Device H update',
        ];

        DB::table('user_sync_revisions')->insertOrIgnore([
            'user_id' => $owner['id'],
            'domain' => 'wellness',
            'revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $directory = sys_get_temp_dir().'/raven23-sync-race-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700, true));
        $barrier = $directory.'/start';
        $worker = base_path('tests/Fixtures/ConcurrentWellnessSyncWorker.php');
        $processes = [];
        $pipes = [];
        $workerMetrics = [];

        try {
            $baseEnvironment = getenv();
            if (! is_array($baseEnvironment)) {
                $baseEnvironment = [];
            }
            unset($baseEnvironment['NEON_DB_URL']);
            $baseEnvironment = array_merge($baseEnvironment, [
                'APP_ENV' => 'testing',
                'APP_KEY' => (string) config('app.key'),
                'APP_URL' => 'http://localhost',
                'DB_CONNECTION' => 'pgsql',
                'DB_URL' => $this->databaseUrl,
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'LOG_CHANNEL' => 'stderr',
            ]);

            foreach ($tokens as $index => $token) {
                $session = $this->wellnessSession($sessionId, $stamps[$index]);
                $session['deviceId'] = 'parallel-device-'.$index;
                $session['title'] = $titles[$index];
                $payload = [
                    'client_time_utc' => $this->clientTime(),
                    'sessions' => [$session],
                    'daily' => [],
                    'limit' => 450,
                ];

                $environment = array_merge($baseEnvironment, [
                    'RAVEN23_SYNC_TOKEN' => $token,
                    'RAVEN23_SYNC_PAYLOAD' => base64_encode(json_encode(
                        $payload,
                        JSON_THROW_ON_ERROR,
                    )),
                    'RAVEN23_SYNC_READY_FILE' => $directory.'/ready-'.$index,
                    'RAVEN23_SYNC_ERROR_FILE' => $directory.'/error-'.$index,
                    'RAVEN23_SYNC_BARRIER_FILE' => $barrier,
                ]);
                $process = proc_open(
                    [PHP_BINARY, $worker],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $workerPipes,
                    base_path(),
                    $environment,
                );
                $this->assertIsResource($process, 'Unable to start a parallel sync worker.');
                fclose($workerPipes[0]);
                $processes[] = $process;
                $pipes[] = [$workerPipes[1], $workerPipes[2]];
            }

            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                $ready = 0;
                for ($index = 0; $index < count($tokens); $index++) {
                    if (is_file($directory.'/ready-'.$index)) {
                        $ready++;
                    }
                }
                if ($ready === count($tokens)) {
                    break;
                }
                usleep(10_000);
            }

            $missingWorkers = [];
            for ($index = 0; $index < count($tokens); $index++) {
                if (! is_file($directory.'/ready-'.$index)) {
                    $errorFile = $directory.'/error-'.$index;
                    $diagnostic = is_file($errorFile)
                        ? trim((string) file_get_contents($errorFile))
                        : 'worker did not report a startup error';
                    $state = proc_get_status($processes[$index]);
                    $missingWorkers[] = 'worker '.$index.' (running='
                        .($state['running'] ? 'yes' : 'no').'): '.$diagnostic;
                }
            }
            $this->assertSame(
                [],
                $missingWorkers,
                'Parallel sync workers failed before the start barrier: '.implode(' | ', $missingWorkers),
            );
            file_put_contents($barrier, 'go');

            foreach ($processes as $index => $process) {
                $stdout = stream_get_contents($pipes[$index][0]);
                $stderr = stream_get_contents($pipes[$index][1]);
                fclose($pipes[$index][0]);
                fclose($pipes[$index][1]);

                $exitCode = proc_close($process);
                $processes[$index] = null;
                $this->assertSame(0, $exitCode, 'Parallel sync worker failed: '.$stderr);
                $result = json_decode(trim((string) $stdout), true, 32, JSON_THROW_ON_ERROR);
                $this->assertSame(200, $result['status']);
                $this->assertTrue($result['success']);
                $this->assertGreaterThan(0, $result['request_bytes']);
                $this->assertGreaterThan(0, $result['response_bytes']);
                $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
                $workerMetrics[] = $result;
            }
        } finally {
            foreach ($processes as $index => $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    fclose($pipes[$index][0]);
                    fclose($pipes[$index][1]);
                    proc_close($process);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($directory);
        }

        $stored = DB::table('user_wellness_sessions')
            ->where('user_id', $owner['id'])
            ->where('session_id', $sessionId)
            ->first();
        $this->assertNotNull($stored);
        $this->assertSame(5000, (int) $stored->updated_at_utc);
        $this->assertNull($stored->deleted_at_utc);
        $storedPayload = json_decode((string) $stored->payload, true);
        $this->assertSame('Device C newest update', $storedPayload['title']);
        $this->assertSame(1, DB::table('user_wellness_sessions')
            ->where('user_id', $owner['id'])
            ->count());

        $dailyRow = DB::table('user_wellness_daily')
            ->where('user_id', $owner['id'])
            ->where('local_day', '2026-11-23')
            ->first();
        $this->assertNotNull($dailyRow);
        $dailyPayload = json_decode((string) $dailyRow->payload, true);
        $this->assertSame(60000, (int) $dailyPayload['watched_ms']);
        $this->assertSame(1, (int) $dailyPayload['session_count']);

        $changeStamps = DB::table('user_wellness_changes')
            ->where('user_id', $owner['id'])
            ->where('entity_type', 'session')
            ->where('entity_key', $sessionId)
            ->pluck('payload')
            ->map(fn ($payload): int => (int) json_decode((string) $payload, true, 32, JSON_THROW_ON_ERROR)['updated_at_utc'])
            ->all();
        $stampCounts = array_count_values($changeStamps);
        $staleConflictWrites = array_sum(array_map(fn (int $count): int => max(0, $count - 1), $stampCounts));
        $this->assertCount(count($tokens), $changeStamps);
        $this->assertGreaterThanOrEqual(0, $staleConflictWrites);
        $this->assertLessThanOrEqual(count($tokens) - 1, $staleConflictWrites);

        $requestBytes = array_sum(array_column($workerMetrics, 'request_bytes'));
        $responseBytes = array_sum(array_column($workerMetrics, 'response_bytes'));
        $latencies = array_map(fn (array $metric): float => (float) $metric['latency_ms'], $workerMetrics);
        sort($latencies);
        $averageLatency = array_sum($latencies) / count($latencies);
        $maximumLatency = max($latencies);
        $conflictRate = $staleConflictWrites / count($tokens);

        fwrite(STDERR, 'RAVEN23_SYNC_SYNTHETIC_METRICS '.json_encode([
            'concurrent_clients' => count($tokens),
            'uploaded_session_records' => count($tokens),
            'request_bytes' => $requestBytes,
            'response_bytes' => $responseBytes,
            'average_request_latency_ms' => round($averageLatency, 3),
            'max_request_latency_ms' => round($maximumLatency, 3),
            'stale_conflict_writes' => $staleConflictWrites,
            'stale_conflict_rate' => round($conflictRate, 4),
            'winning_updated_at_utc' => (int) $stored->updated_at_utc,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
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
