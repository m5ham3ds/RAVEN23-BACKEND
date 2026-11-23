<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresPlaylistSyncTest extends TestCase
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
        $suffix = bin2hex(random_bytes(4));
        $user = User::create([
            'name' => 'RAVEN23 PostgreSQL Playlist',
            'email' => 'raven-playlist-'.$suffix.'@example.test',
            'username' => 'raven_playlist_'.$suffix,
            'password' => 'Raven-Playlist-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL playlist sync test')->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);

        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    public function test_postgresql_playlist_operations_are_owner_scoped_idempotent_and_conflict_safe(): void
    {
        $owner = $this->createAccount();
        $other = $this->createAccount();
        $playlistId = '11111111-1111-4111-8111-111111111111';

        $created = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/playlists', [
                'operations' => [
                    [
                        'operation_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                        'action' => 'playlist.create',
                        'playlist_id' => $playlistId,
                        'name' => 'PostgreSQL list',
                    ],
                    [
                        'operation_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                        'action' => 'item.add',
                        'playlist_id' => $playlistId,
                        'media_type' => 'movie',
                        'media_id' => 550,
                        'title' => 'Fight Club',
                        'genre_ids' => [18, 53],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonCount(1, 'playlists')
            ->assertJsonCount(1, 'items');

        Auth::forgetGuards();
        $otherPull = $this->withToken($other['token'])
            ->postJson('/api/v1/sync/playlists', ['since_revision' => 0])
            ->assertOk()
            ->assertJsonPath('server_revision', 0)
            ->assertJsonCount(0, 'playlists')
            ->assertJsonCount(0, 'items');

        Auth::forgetGuards();
        $conflict = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 2,
                'operations' => [
                    [
                        'operation_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                        'action' => 'playlist.update',
                        'playlist_id' => $playlistId,
                        'base_version' => 1,
                        'name' => 'Updated list',
                    ],
                    [
                        'operation_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                        'action' => 'playlist.reorder',
                        'playlist_id' => $playlistId,
                        'base_version' => 1,
                        'items' => [['media_type' => 'movie', 'media_id' => 550]],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.code', 'version_conflict')
            ->assertJsonPath('results.1.current_version', 2)
            ->assertJsonPath('playlists.0.version', 2);

        Auth::forgetGuards();
        $replay = $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/playlists', [
                'since_revision' => 3,
                'operations' => [
                    [
                        'operation_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                        'action' => 'playlist.create',
                        'playlist_id' => $playlistId,
                        'name' => 'PostgreSQL list',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 3)
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonCount(0, 'playlists')
            ->assertJsonCount(0, 'items');

        $this->assertSame(3, $conflict->json('server_revision'));
        $this->assertSame(3, $replay->json('server_revision'));
        $this->assertDatabaseHas('user_playlists', [
            'id' => $playlistId,
            'user_id' => $owner['id'],
            'name' => 'Updated list',
            'version' => 2,
        ]);
        $this->assertDatabaseHas('user_playlist_items', [
            'playlist_id' => $playlistId,
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
        $this->assertDatabaseMissing('user_playlists', [
            'id' => $playlistId,
            'user_id' => $other['id'],
        ]);
        $this->assertDatabaseCount('user_playlist_operations', 4);
        $this->assertDatabaseCount('user_playlist_changes', 3);
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
