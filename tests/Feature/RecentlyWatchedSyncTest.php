<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecentlyWatchedSyncTest extends TestCase
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
        $email = 'recent-'.$suffix.'@example.test';
        Auth::forgetGuards();
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN23 Recent Test',
            'email' => $email,
            'username' => 'recent_'.$suffix,
            'password' => 'Raven-Recent-Test-Password-42!',
        ])->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
            'email' => $email,
        ];
    }

    private function clientTime(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function movie(int $stamp, string $title = 'Fight Club', ?int $deleted = null): array
    {
        return [
            'id' => 550,
            'movie_id' => 550,
            'title' => $title,
            'release_year' => 1999,
            'elapsed' => 600,
            'remaining' => 300,
            'date_watched' => '2026-11-23T10:00:00.000Z',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'updated_at_utc' => $stamp,
            'deleted_at_utc' => $deleted,
        ];
    }

    private function episode(int $stamp): array
    {
        return [
            'id' => 62085,
            'episode_id' => 62085,
            'series_id' => 1396,
            'series_name' => 'Breaking Bad',
            'episode_name' => 'Pilot',
            'episode_num' => 1,
            'season_num' => 1,
            'elapsed' => 900,
            'remaining' => 1200,
            'date_added' => '2026-11-23T10:00:00.000Z',
            'poster_path' => '/episode-poster.jpg',
            'backdrop_path' => '/episode-still.jpg',
            'updated_at_utc' => $stamp,
            'deleted_at_utc' => null,
        ];
    }

    public function test_full_sync_round_trips_movies_episodes_and_acknowledges_exact_uploads(): void
    {
        $account = $this->registerAccount();

        $response = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie(1000)],
                'episodes' => [$this->episode(1100)],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('full', true)
            ->assertJsonCount(1, 'movies')
            ->assertJsonCount(1, 'episodes')
            ->assertJsonPath('movies.0.id', 550)
            ->assertJsonPath('movies.0.movie_id', 550)
            ->assertJsonPath('movies.0.title', 'Fight Club')
            ->assertJsonPath('episodes.0.episode_id', 62085)
            ->assertJsonPath('episodes.0.series_id', 1396)
            ->assertJsonPath('episodes.0.episode_num', 1)
            ->assertJsonCount(1, 'uploads.movies')
            ->assertJsonCount(1, 'uploads.episodes');

        $revision = (int) $response->json('server_revision');
        $this->assertSame(2, $revision);
        $this->assertSame(1000, $response->json('uploads.movies.0.updated_at_utc'));
        $this->assertSame(1100, $response->json('uploads.episodes.0.updated_at_utc'));
        $this->assertDatabaseCount('user_recent_media', 2);

        Auth::forgetGuards();
        $again = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => $revision,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie(1000)],
                'episodes' => [$this->episode(1100)],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'movies')
            ->assertJsonCount(1, 'episodes');

        $this->assertSame($revision, $again->json('server_revision'));
        $this->assertDatabaseCount('user_recent_media', 2);
        $this->assertDatabaseHas('user_recent_media', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 550,
            'revision' => 1,
        ]);
    }

    public function test_stale_playback_does_not_overwrite_a_newer_server_version(): void
    {
        $account = $this->registerAccount();
        $first = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie(2000, 'Newer title')],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $stale = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => $first->json('server_revision'),
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie(1000, 'Older device title')],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'movies')
            ->assertJsonPath('movies.0.title', 'Newer title');

        $this->assertSame(1000, $stale->json('uploads.movies.0.updated_at_utc'));
        $this->assertSame(2000, $stale->json('movies.0.updated_at_utc'));
        $this->assertDatabaseHas('user_recent_media', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 550,
            'updated_at_utc' => 2000,
        ]);
    }

    public function test_newer_tombstone_wins_and_cannot_be_resurrected_by_an_old_device(): void
    {
        $account = $this->registerAccount();
        $baseStamp = $this->clientTime() - 10_000;
        $deletedStamp = $baseStamp + 1_000;
        $staleStamp = $baseStamp + 500;
        $created = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie($baseStamp)],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $deleted = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => $created->json('server_revision'),
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie($deletedStamp, 'Fight Club', $deletedStamp)],
            ])
            ->assertOk()
            ->assertJsonPath('movies.0.deleted_at_utc', $deletedStamp);

        Auth::forgetGuards();
        $stale = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => $deleted->json('server_revision'),
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie($staleStamp, 'Old device copy')],
            ])
            ->assertOk()
            ->assertJsonPath('movies.0.deleted_at_utc', $deletedStamp);

        $this->assertSame($deletedStamp, $stale->json('movies.0.updated_at_utc'));
        $this->assertDatabaseCount('user_recent_media', 1);
        $this->assertDatabaseHas('user_recent_media', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 550,
            'deleted_at_utc' => $deletedStamp,
        ]);
    }

    public function test_newer_progress_supersedes_an_older_tombstone(): void
    {
        $account = $this->registerAccount();

        $response = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [[
                    ...$this->movie(3000, 'Resumed title', 2000),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('movies.0.updated_at_utc', 3000)
            ->assertJsonPath('movies.0.deleted_at_utc', null)
            ->assertJsonPath('uploads.movies.0.deleted_at_utc', null);

        $this->assertSame(1, $response->json('server_revision'));
        $this->assertDatabaseHas('user_recent_media', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 550,
            'updated_at_utc' => 3000,
            'deleted_at_utc' => null,
        ]);
    }

    public function test_recent_sync_isolated_between_accounts_even_for_the_same_media_id(): void
    {
        $owner = $this->registerAccount();
        $other = $this->registerAccount();

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie(1000, 'Owner title')],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $otherPull = $this->withToken($other['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
            ])
            ->assertOk()
            ->assertJsonCount(0, 'movies');

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie(2000, 'Other title')],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $ownerPull = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
            ])
            ->assertOk()
            ->assertJsonCount(1, 'movies')
            ->assertJsonPath('movies.0.title', 'Owner title');

        $this->assertSame(0, $otherPull->json('server_revision'));
        $this->assertSame(1, $ownerPull->json('server_revision'));
        $this->assertDatabaseCount('user_recent_media', 2);
    }

    public function test_clock_skew_and_revision_ahead_return_the_flutter_retry_contract(): void
    {
        $account = $this->registerAccount();

        $future = $this->clientTime() + 2 * 86_400_000;
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie($future)],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'clock_skew_detected')
            ->assertJsonStructure(['server_time_utc']);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 99,
                'client_time_utc' => $this->clientTime(),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['since_revision']]);

        $this->assertDatabaseCount('user_recent_media', 0);
    }

    public function test_progress_validation_and_authentication_fail_before_writes(): void
    {
        $account = $this->registerAccount();

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [[...$this->movie(1000), 'elapsed' => -1]],
            ])
            ->assertUnprocessable();

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'episodes' => [[...$this->episode(1000), 'episode_id' => 0]],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('user_recent_media', 0);

        Auth::forgetGuards();
        $this->withToken('invalid-raven23-test-token')
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
            ])->assertUnauthorized();
    }

    public function test_export_v2_includes_only_the_authenticated_accounts_library_data(): void
    {
        $owner = $this->registerAccount();
        $other = $this->registerAccount();

        DB::table('user_bookmarks')->insert([
            'user_id' => $owner['id'],
            'media_type' => 'movie',
            'media_id' => 550,
            'metadata' => json_encode(['id' => 550, 'title' => 'Owner bookmark']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
                'movies' => [$this->movie(1000, 'Owner progress')],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $export = $this->withToken($owner['token'])
            ->getJson('/api/v1/user/data-export')
            ->assertOk()
            ->assertJsonPath('data.format_version', 3)
            ->assertJsonPath('data.bookmarks.movies.0.title', 'Owner bookmark')
            ->assertJsonPath('data.recently_watched.movies.0.title', 'Owner progress');

        $this->assertSame($owner['id'], $export->json('data.account.id'));
        $this->assertStringNotContainsString($other['email'], $export->getContent());
        $this->assertArrayNotHasKey('password', $export->json('data.account'));
        $this->assertArrayNotHasKey('google_id', $export->json('data.account'));
    }
}
