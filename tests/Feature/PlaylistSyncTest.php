<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlaylistSyncTest extends TestCase
{
    private const PLAYLIST = '11111111-1111-4111-8111-111111111111';

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
            'name' => 'RAVEN23 Playlist Test',
            'email' => 'playlist-'.$suffix.'@example.test',
            'username' => 'playlist_'.$suffix,
            'password' => 'Raven-Playlist-Test-Password-42!',
        ])->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
            'email' => 'playlist-'.$suffix.'@example.test',
        ];
    }

    private function createOperation(string $operationId, string $playlistId = self::PLAYLIST): array
    {
        return [
            'operation_id' => $operationId,
            'action' => 'playlist.create',
            'playlist_id' => $playlistId,
            'name' => 'Family night',
            'description' => 'Saved for later',
        ];
    }

    private function addOperation(
        string $operationId,
        int $mediaId,
        string $playlistId = self::PLAYLIST,
        string $mediaType = 'movie',
    ): array {
        return [
            'operation_id' => $operationId,
            'action' => 'item.add',
            'playlist_id' => $playlistId,
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'title' => 'Title '.$mediaId,
            'poster_path' => '/poster-'.$mediaId.'.jpg',
            'backdrop_path' => '/backdrop-'.$mediaId.'.jpg',
            'vote_average' => 8.2,
            'release_date' => '2026-11-23',
            'genre_ids' => [28, 12],
        ];
    }

    public function test_playlist_operations_round_trip_and_are_idempotent(): void
    {
        $account = $this->registerAccount();
        $operations = [
            $this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
            $this->addOperation('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 550),
            $this->addOperation('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 1396, self::PLAYLIST, 'tv'),
        ];

        $first = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', ['operations' => $operations])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('full', true)
            ->assertJsonCount(1, 'playlists')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('playlists.0.id', self::PLAYLIST)
            ->assertJsonPath('playlists.0.name', 'Family night')
            ->assertJsonPath('playlists.0.version', 1)
            ->assertJsonPath('items.0.media_type', 'movie')
            ->assertJsonPath('items.0.genre_ids.0', 28)
            ->assertJsonCount(3, 'results')
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied')
            ->assertJsonPath('results.2.status', 'applied');

        $again = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 3,
                'operations' => $operations,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('full', false)
            ->assertJsonCount(0, 'playlists')
            ->assertJsonCount(0, 'items')
            ->assertJsonCount(3, 'results');

        $this->assertSame('applied', $again->json('results.0.status'));
        $this->assertDatabaseCount('user_playlists', 1);
        $this->assertDatabaseCount('user_playlist_items', 2);
        $this->assertDatabaseCount('user_playlist_operations', 3);
        $this->assertDatabaseCount('user_playlist_changes', 3);
        $this->assertSame(3, $first->json('server_revision'));
    }

    public function test_stale_version_is_rejected_and_current_version_is_returned(): void
    {
        $account = $this->registerAccount();
        $response = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [
                    $this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
                    [
                        'operation_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                        'action' => 'playlist.update',
                        'playlist_id' => self::PLAYLIST,
                        'base_version' => 1,
                        'name' => 'Renamed',
                    ],
                    [
                        'operation_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                        'action' => 'playlist.update',
                        'playlist_id' => self::PLAYLIST,
                        'base_version' => 1,
                        'description' => 'Stale description',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('results.1.status', 'applied')
            ->assertJsonPath('results.2.status', 'rejected')
            ->assertJsonPath('results.2.code', 'version_conflict')
            ->assertJsonPath('results.2.current_version', 2)
            ->assertJsonPath('playlists.0.name', 'Renamed')
            ->assertJsonPath('playlists.0.description', 'Saved for later')
            ->assertJsonPath('playlists.0.version', 2);

        $this->assertSame(2, $response->json('server_revision'));
        $this->assertDatabaseHas('user_playlists', [
            'id' => self::PLAYLIST,
            'user_id' => $account['id'],
            'name' => 'Renamed',
            'description' => 'Saved for later',
            'version' => 2,
        ]);
    }

    public function test_delete_and_remove_emit_tombstones_for_full_and_delta_sync(): void
    {
        $account = $this->registerAccount();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [
                    $this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
                    $this->addOperation('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 550),
                ],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $deleted = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 2,
                'operations' => [
                    [
                        'operation_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                        'action' => 'playlist.delete',
                        'playlist_id' => self::PLAYLIST,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('playlists.0.deleted', true)
            ->assertJsonPath('items.0.deleted', true);

        Auth::forgetGuards();
        $full = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', ['since_revision' => 0])
            ->assertOk()
            ->assertJsonPath('full', true)
            ->assertJsonCount(1, 'playlists')
            ->assertJsonPath('playlists.0.deleted', true)
            ->assertJsonPath('items.0.deleted', true);

        $this->assertDatabaseMissing('user_playlists', ['id' => self::PLAYLIST]);
        $this->assertDatabaseMissing('user_playlist_items', ['playlist_id' => self::PLAYLIST]);
        $this->assertSame(3, $deleted->json('server_revision'));
        $this->assertSame(3, $full->json('server_revision'));
    }

    public function test_reorder_changes_order_and_increments_the_playlist_version(): void
    {
        $account = $this->registerAccount();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [
                    $this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
                    $this->addOperation('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 1),
                    $this->addOperation('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 1, self::PLAYLIST, 'tv'),
                    $this->addOperation('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 2),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 4);

        Auth::forgetGuards();
        $reordered = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 4,
                'operations' => [[
                    'operation_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                    'action' => 'playlist.reorder',
                    'playlist_id' => self::PLAYLIST,
                    'base_version' => 1,
                    'items' => [
                        ['media_type' => 'movie', 'media_id' => 2],
                        ['media_type' => 'movie', 'media_id' => 1],
                        ['media_type' => 'tv', 'media_id' => 1],
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 5)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('playlists.0.version', 2)
            ->assertJsonCount(3, 'items');

        $rows = DB::table('user_playlist_items')
            ->where('playlist_id', self::PLAYLIST)
            ->orderBy('position')
            ->get(['media_type', 'media_id', 'position']);
        $this->assertSame([
            ['media_type' => 'movie', 'media_id' => 2, 'position' => 0],
            ['media_type' => 'movie', 'media_id' => 1, 'position' => 1],
            ['media_type' => 'tv', 'media_id' => 1, 'position' => 2],
        ], $rows->map(static fn ($row): array => [
            'media_type' => $row->media_type,
            'media_id' => (int) $row->media_id,
            'position' => (int) $row->position,
        ])->all());
        $this->assertSame(5, $reordered->json('server_revision'));
    }

    public function test_item_remove_is_idempotent_compacts_positions_and_emits_changes(): void
    {
        $account = $this->registerAccount();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [
                    $this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
                    $this->addOperation('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 550),
                    $this->addOperation('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 551),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3);

        Auth::forgetGuards();
        $removed = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 3,
                'operations' => [[
                    'operation_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                    'action' => 'item.remove',
                    'playlist_id' => self::PLAYLIST,
                    'media_type' => 'movie',
                    'media_id' => 550,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 4)
            ->assertJsonPath('items.0.deleted', true);

        $this->assertDatabaseHas('user_playlist_items', [
            'playlist_id' => self::PLAYLIST,
            'media_type' => 'movie',
            'media_id' => 551,
            'position' => 0,
        ]);

        Auth::forgetGuards();
        $added = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 4,
                'operations' => [
                    $this->addOperation('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 552),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 5);

        $this->assertDatabaseHas('user_playlist_items', [
            'playlist_id' => self::PLAYLIST,
            'media_type' => 'movie',
            'media_id' => 551,
            'position' => 0,
        ]);
        $this->assertDatabaseHas('user_playlist_items', [
            'playlist_id' => self::PLAYLIST,
            'media_type' => 'movie',
            'media_id' => 552,
            'position' => 1,
        ]);

        Auth::forgetGuards();
        $again = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 5,
                'operations' => [[
                    'operation_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                    'action' => 'item.remove',
                    'playlist_id' => self::PLAYLIST,
                    'media_type' => 'movie',
                    'media_id' => 550,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 5)
            ->assertJsonPath('results.0.code', 'already_removed')
            ->assertJsonCount(0, 'items');

        $this->assertDatabaseMissing('user_playlist_items', [
            'playlist_id' => self::PLAYLIST,
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
        $this->assertSame(4, $removed->json('server_revision'));
        $this->assertSame(5, $added->json('server_revision'));
        $this->assertSame(5, $again->json('server_revision'));
    }

    public function test_pagination_is_pinned_to_until_revision_while_new_items_arrive(): void
    {
        $account = $this->registerAccount();
        $operations = [
            $this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
        ];
        for ($index = 1; $index <= 201; $index++) {
            $suffix = str_pad((string) $index, 12, '0', STR_PAD_LEFT);
            $operations[] = $this->addOperation(
                'bbbbbbbb-bbbb-4bbb-8bbb-'.$suffix,
                $index,
            );
        }

        $first = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', ['operations' => $operations])
            ->assertOk()
            ->assertJsonPath('server_revision', 202)
            ->assertJsonCount(1, 'playlists')
            ->assertJsonCount(200, 'items');

        $cursor = $first->json('next_cursor');
        $this->assertIsInt($cursor);

        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 202,
                'operations' => [
                    $this->addOperation('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 999),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 203);

        Auth::forgetGuards();
        $second = $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 0,
                'until_revision' => 202,
                'cursor' => $cursor,
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 202)
            ->assertJsonCount(0, 'playlists')
            ->assertJsonCount(1, 'items');

        $this->assertNull($second->json('next_cursor'));
        $this->assertNotSame(999, $second->json('items.0.media_id'));
        $this->assertDatabaseHas('user_playlist_items', [
            'playlist_id' => self::PLAYLIST,
            'media_id' => 999,
        ]);
    }

    public function test_playlist_ownership_and_operation_ids_are_isolated_between_accounts(): void
    {
        $owner = $this->registerAccount();
        $other = $this->registerAccount();

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [$this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'playlists');

        Auth::forgetGuards();
        $otherResponse = $this->withToken($other['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [$this->createOperation('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.code', 'playlist_id_taken')
            ->assertJsonCount(0, 'playlists')
            ->assertJsonCount(0, 'items');

        Auth::forgetGuards();
        $reusedId = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [[
                    'operation_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'action' => 'playlist.delete',
                    'playlist_id' => self::PLAYLIST,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.code', 'operation_id_reused');

        $this->assertSame(0, $otherResponse->json('server_revision'));
        $this->assertSame(1, $reusedId->json('server_revision'));
        $this->assertDatabaseHas('user_playlists', [
            'id' => self::PLAYLIST,
            'user_id' => $owner['id'],
        ]);
    }

    public function test_invalid_batches_do_not_create_rows(): void
    {
        $account = $this->registerAccount();

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [[
                    'operation_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'action' => 'item.add',
                    'playlist_id' => self::PLAYLIST,
                    'media_type' => 'manga',
                    'media_id' => 10,
                ]],
            ])
            ->assertUnprocessable();

        $this->withToken($account['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [$this->createOperation('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')],
                'until_revision' => 0,
                'cursor' => 0,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('user_playlists', 0);
        $this->assertDatabaseCount('user_playlist_items', 0);
        $this->assertDatabaseCount('user_playlist_operations', 0);
    }

    public function test_playlist_exports_include_only_the_authenticated_owner_data(): void
    {
        $owner = $this->registerAccount();
        $other = $this->registerAccount();

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [
                    $this->createOperation('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
                    $this->addOperation('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 550),
                ],
            ])
            ->assertOk();

        Auth::forgetGuards();
        $export = $this->withToken($owner['token'])
            ->getJson('/api/v1/user/data-export')
            ->assertOk()
            ->assertJsonPath('data.format_version', 3)
            ->assertJsonCount(1, 'data.playlists')
            ->assertJsonPath('data.playlists.0.name', 'Family night')
            ->assertJsonCount(1, 'data.playlists.0.items');

        $this->assertStringNotContainsString($other['email'] ?? '', $export->getContent());
        $this->assertDatabaseCount('user_playlist_items', 1);
    }
}
