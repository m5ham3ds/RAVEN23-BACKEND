<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookmarkSyncTest extends TestCase
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
        $email = 'bookmarks-'.$suffix.'@example.test';
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN23 Bookmark Test',
            'email' => $email,
            'username' => 'bookmark_'.$suffix,
            'password' => 'Raven-Bookmark-Test-Password-42!',
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

    private function versionedOperation(
        string $operationId,
        string $action,
        string $mediaType,
        int $mediaId,
        int $baseRevision,
        array $metadata = [],
    ): array {
        $operation = [
            'operation_id' => $operationId,
            'action' => $action,
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'base_revision' => $baseRevision,
        ];

        if ($action === 'bookmark.add') {
            $operation['metadata'] = $metadata;
        }

        return $operation;
    }

    public function test_bookmark_sync_returns_a_full_idempotent_movie_and_tv_union(): void
    {
        $account = $this->registerAccount();

        $response = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'movies' => [
                    [
                        'id' => 550,
                        'title' => 'Initial title',
                        'genre_ids' => '28,12',
                        'poster_path' => '/fight-club.webp',
                        'vote_average' => 8.4,
                    ],
                    [
                        'id' => 550,
                        'title' => 'Updated title',
                        'genre_ids' => [28, 12],
                        'poster_path' => '/fight-club.webp',
                        'vote_average' => 8.4,
                    ],
                ],
                'tvShows' => [
                    [
                        'id' => 1399,
                        'name' => 'Game of Thrones',
                        'original_title' => 'Game of Thrones',
                        'first_air_date' => '2011-04-17',
                        'genre_ids' => [10765, 18],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'movies')
            ->assertJsonCount(1, 'tvShows')
            ->assertJsonPath('movies.0.id', 550)
            ->assertJsonPath('movies.0.title', 'Updated title')
            ->assertJsonPath('movies.0.genre_ids', [28, 12])
            ->assertJsonPath('tvShows.0.id', 1399)
            ->assertJsonPath('tvShows.0.name', 'Game of Thrones')
            ->assertJsonPath('tvShows.0.genre_ids', [10765, 18]);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [])
            ->assertOk()
            ->assertJsonCount(1, 'movies')
            ->assertJsonCount(1, 'tvShows')
            ->assertJsonPath('movies.0.title', 'Updated title');

        $this->assertDatabaseCount('user_bookmarks', 2);
        $this->assertDatabaseHas('user_bookmarks', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
        $this->assertNotNull($response->json('movies.0.date_added'));
    }

    public function test_bookmark_reads_and_deletes_are_scoped_to_the_authenticated_owner(): void
    {
        $owner = $this->registerAccount();
        $other = $this->registerAccount();

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'movies' => [['id' => 42, 'title' => 'Owner movie']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'movies');

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->postJson('/api/v1/sync/bookmarks', [])
            ->assertOk()
            ->assertJsonCount(0, 'movies')
            ->assertJsonCount(0, 'tvShows');

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->deleteJson('/api/v1/sync/bookmarks/movie/42')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_bookmarks', [
            'user_id' => $owner['id'],
            'media_type' => 'movie',
            'media_id' => 42,
        ]);
        $this->assertDatabaseMissing('user_bookmarks', [
            'user_id' => $other['id'],
            'media_type' => 'movie',
            'media_id' => 42,
        ]);
    }

    public function test_batch_and_single_deletions_are_idempotent_and_owner_scoped(): void
    {
        $account = $this->registerAccount();

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'movies' => [
                    ['id' => 1, 'title' => 'Keep'],
                    ['id' => 2, 'title' => 'Remove'],
                ],
                'tvShows' => [['id' => 7, 'name' => 'Remove show']],
                'deleted_media' => [
                    ['media_type' => 'movie', 'media_id' => 2],
                    ['media_type' => 'tv', 'media_id' => 7],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'movies')
            ->assertJsonPath('movies.0.id', 1)
            ->assertJsonCount(0, 'tvShows');

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->deleteJson('/api/v1/sync/bookmarks/movie/1')
            ->assertOk()
            ->assertJsonPath('success', true);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->deleteJson('/api/v1/sync/bookmarks/movie/1')
            ->assertOk()
            ->assertJsonPath('success', true);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [])
            ->assertOk()
            ->assertJsonCount(0, 'movies')
            ->assertJsonCount(0, 'tvShows');

        $this->assertDatabaseCount('user_bookmarks', 0);
    }

    public function test_invalid_bookmark_types_and_media_ids_are_rejected_before_writes(): void
    {
        $account = $this->registerAccount();

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'movies' => [['id' => 0, 'title' => 'Invalid']],
            ])
            ->assertUnprocessable();

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'deleted_media' => [
                    ['media_type' => 'anime', 'media_id' => 10],
                ],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('user_bookmarks', 0);
    }

    public function test_versioned_bookmark_operations_are_replay_safe_and_return_full_and_delta_sync(): void
    {
        $account = $this->registerAccount();
        $operations = [
            $this->versionedOperation(
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'bookmark.add',
                'movie',
                550,
                0,
                ['title' => 'Fight Club', 'genre_ids' => [18, 53]],
            ),
            $this->versionedOperation(
                'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'bookmark.add',
                'tv',
                1399,
                0,
                ['name' => 'Game of Thrones', 'genre_ids' => [10765, 18]],
            ),
        ];

        $first = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_time_utc' => $this->clientTime(),
                'operations' => $operations,
            ])
            ->assertOk()
            ->assertJsonPath('full', true)
            ->assertJsonPath('server_revision', 2)
            ->assertJsonCount(1, 'movies')
            ->assertJsonCount(1, 'tvShows')
            ->assertJsonPath('movies.0.id', 550)
            ->assertJsonPath('movies.0.title', 'Fight Club')
            ->assertJsonPath('tvShows.0.id', 1399)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        Auth::forgetGuards();
        $replay = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 2,
                'client_time_utc' => $this->clientTime(),
                'operations' => $operations,
            ])
            ->assertOk()
            ->assertJsonPath('full', false)
            ->assertJsonPath('server_revision', 2)
            ->assertJsonCount(0, 'changes')
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        Auth::forgetGuards();
        $deleted = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 2,
                'client_time_utc' => $this->clientTime(),
                'operations' => [
                    $this->versionedOperation(
                        'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                        'bookmark.remove',
                        'movie',
                        550,
                        1,
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.action', 'bookmark.remove')
            ->assertJsonPath('changes.0.deleted', true)
            ->assertJsonPath('changes.0.media_id', 550);

        $this->assertSame(2, $first->json('server_revision'));
        $this->assertSame(2, $replay->json('server_revision'));
        $this->assertSame(3, $deleted->json('server_revision'));
        $this->assertDatabaseCount('user_bookmark_operations', 3);
        $this->assertDatabaseCount('user_bookmark_changes', 3);
        $this->assertDatabaseMissing('user_bookmarks', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
        $this->assertDatabaseHas('user_sync_revisions', [
            'user_id' => $account['id'],
            'domain' => 'bookmarks',
            'revision' => 3,
        ]);
    }

    public function test_versioned_bookmarks_reject_stale_writes_and_operation_id_payload_reuse(): void
    {
        $account = $this->registerAccount();
        $add = $this->versionedOperation(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'bookmark.add',
            'movie',
            550,
            0,
            ['title' => 'Fight Club'],
        );

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_time_utc' => $this->clientTime(),
                'operations' => [$add],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 1,
                'client_time_utc' => $this->clientTime(),
                'operations' => [
                    $this->versionedOperation(
                        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                        'bookmark.remove',
                        'movie',
                        550,
                        1,
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2);

        $staleAdd = $this->versionedOperation(
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'bookmark.add',
            'movie',
            550,
            1,
            ['title' => 'Old device copy'],
        );

        Auth::forgetGuards();
        $conflict = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 2,
                'client_time_utc' => $this->clientTime(),
                'operations' => [$staleAdd],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.code', 'stale_write')
            ->assertJsonPath('results.0.current_revision', 2);

        Auth::forgetGuards();
        $replay = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 2,
                'client_time_utc' => $this->clientTime(),
                'operations' => [$staleAdd],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.current_revision', 2);

        $reusedId = $staleAdd;
        $reusedId['metadata'] = ['title' => 'Different payload'];
        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 2,
                'client_time_utc' => $this->clientTime(),
                'operations' => [$reusedId],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'operation_id_reused');

        $this->assertSame('conflict', $conflict->json('results.0.status'));
        $this->assertSame('conflict', $replay->json('results.0.status'));
        $this->assertDatabaseCount('user_bookmark_operations', 3);
        $this->assertDatabaseCount('user_bookmark_changes', 2);
        $this->assertDatabaseMissing('user_bookmarks', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
    }

    public function test_legacy_bookmark_adds_and_deletes_are_bridged_into_revision_tombstones(): void
    {
        $account = $this->registerAccount();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'movies' => [[
                    'id' => 880,
                    'title' => 'Legacy client movie',
                    'date_added' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'movies');

        Auth::forgetGuards();
        $baseline = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 0,
                'client_time_utc' => $this->clientTime(),
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('movies.0.id', 880);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->deleteJson('/api/v1/sync/bookmarks/movie/880')
            ->assertOk()
            ->assertJsonPath('success', true);

        Auth::forgetGuards();
        $delta = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 1,
                'client_time_utc' => $this->clientTime(),
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.action', 'bookmark.remove')
            ->assertJsonPath('changes.0.deleted', true)
            ->assertJsonPath('changes.0.media_id', 880);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->deleteJson('/api/v1/sync/bookmarks/movie/880')
            ->assertOk();

        Auth::forgetGuards();
        $staleLegacyUpload = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'movies' => [[
                    'id' => 880,
                    'title' => 'Stale offline copy',
                    'date_added' => now()->subMinute()->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'movies');

        $this->assertSame(1, $baseline->json('server_revision'));
        $this->assertSame(2, $delta->json('server_revision'));
        $this->assertSame([], $staleLegacyUpload->json('movies'));
        $this->assertDatabaseCount('user_bookmark_changes', 2);
        $this->assertDatabaseHas('user_sync_revisions', [
            'user_id' => $account['id'],
            'domain' => 'bookmarks',
            'revision' => 2,
        ]);
        $this->assertDatabaseMissing('user_bookmarks', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 880,
        ]);
    }

    public function test_versioned_bookmark_change_pages_are_pinned_to_the_requested_revision(): void
    {
        $account = $this->registerAccount();
        $baseline = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_time_utc' => $this->clientTime(),
                'operations' => [
                    $this->versionedOperation(
                        '10000000-0000-4000-8000-000000000001',
                        'bookmark.add',
                        'movie',
                        1,
                        0,
                        ['title' => 'Baseline'],
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('current_revision', 1);

        $operations = [];
        for ($index = 1; $index <= 201; $index++) {
            $operations[] = $this->versionedOperation(
                sprintf('20000000-0000-4000-8000-%012d', $index),
                'bookmark.add',
                'movie',
                1000 + $index,
                1,
                ['title' => 'Page '.$index],
            );
        }

        Auth::forgetGuards();
        $firstPage = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 1,
                'client_time_utc' => $this->clientTime(),
                'operations' => $operations,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 202)
            ->assertJsonPath('current_revision', 202)
            ->assertJsonCount(200, 'changes');

        $cursor = $firstPage->json('next_cursor');
        $this->assertIsInt($cursor);

        Auth::forgetGuards();
        $newerWrite = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 202,
                'client_time_utc' => $this->clientTime(),
                'operations' => [
                    $this->versionedOperation(
                        '30000000-0000-4000-8000-000000000001',
                        'bookmark.add',
                        'movie',
                        999,
                        202,
                        ['title' => 'Arrived after snapshot'],
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 203)
            ->assertJsonPath('current_revision', 203);

        Auth::forgetGuards();
        $secondPage = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 1,
                'until_revision' => 202,
                'cursor' => $cursor,
                'client_time_utc' => $this->clientTime(),
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 202)
            ->assertJsonPath('current_revision', 203)
            ->assertJsonPath('until_revision', 202)
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('next_cursor', null);

        Auth::forgetGuards();
        $invalidWindow = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 202,
                'until_revision' => 201,
                'cursor' => 0,
                'client_time_utc' => $this->clientTime(),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['until_revision']]);

        $this->assertSame(1, $baseline->json('server_revision'));
        $this->assertSame(203, $newerWrite->json('server_revision'));
        $this->assertSame(202, $firstPage->json('server_revision'));
        $this->assertSame(202, $secondPage->json('server_revision'));
        $this->assertSame(203, $secondPage->json('current_revision'));
        $this->assertNotNull($secondPage->json('changes.0.media_id'));
        $this->assertSame(422, $invalidWindow->getStatusCode());
        $this->assertDatabaseHas('user_bookmarks', [
            'user_id' => $account['id'],
            'media_type' => 'movie',
            'media_id' => 999,
        ]);
    }

    public function test_bookmark_endpoints_require_an_authenticated_account(): void
    {
        $this->postJson('/api/v1/sync/bookmarks', [])
            ->assertUnauthorized();

        $this->deleteJson('/api/v1/sync/bookmarks/movie/1')
            ->assertUnauthorized();
    }
}
