<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresBookmarkSyncTest extends TestCase
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
            'name' => 'RAVEN23 PostgreSQL Bookmark',
            'email' => 'raven-bookmark-'.$suffix.'@example.test',
            'username' => 'raven_bm_'.$suffix,
            'password' => 'Raven-Bookmark-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL bookmark sync test')->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);

        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function operation(
        string $operationId,
        string $action,
        string $type,
        int $mediaId,
        int $baseRevision,
        array $metadata = [],
    ): array {
        $operation = [
            'operation_id' => $operationId,
            'action' => $action,
            'media_type' => $type,
            'media_id' => $mediaId,
            'base_revision' => $baseRevision,
        ];

        if ($action === 'bookmark.add') {
            $operation['metadata'] = $metadata;
        }

        return $operation;
    }

    public function test_postgresql_revisioned_bookmarks_are_owner_scoped_and_conflict_safe(): void
    {
        $owner = $this->createAccount();
        $other = $this->createAccount();

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 0,
                'client_time_utc' => $this->nowMs(),
                'operations' => [
                    $this->operation(
                        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                        'bookmark.add',
                        'movie',
                        550,
                        0,
                        ['title' => 'PostgreSQL owner movie'],
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 1)
            ->assertJsonPath('movies.0.title', 'PostgreSQL owner movie')
            ->assertJsonPath('results.0.status', 'applied');

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 0,
                'client_time_utc' => $this->nowMs(),
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 0)
            ->assertJsonCount(0, 'movies')
            ->assertJsonCount(0, 'changes');

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 1,
                'client_time_utc' => $this->nowMs(),
                'operations' => [
                    $this->operation(
                        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                        'bookmark.remove',
                        'movie',
                        550,
                        1,
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('changes.0.action', 'bookmark.remove')
            ->assertJsonPath('changes.0.deleted', true);

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/bookmarks', [
                'since_revision' => 2,
                'client_time_utc' => $this->nowMs(),
                'operations' => [
                    $this->operation(
                        'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                        'bookmark.add',
                        'movie',
                        550,
                        1,
                        ['title' => 'Stale PostgreSQL copy'],
                    ),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.current_revision', 2);

        $this->assertDatabaseHas('user_bookmark_changes', [
            'user_id' => $owner['id'],
            'media_type' => 'movie',
            'media_id' => 550,
            'revision' => 2,
            'action' => 'bookmark.remove',
            'deleted' => true,
        ]);
        $this->assertDatabaseMissing('user_bookmarks', [
            'user_id' => $owner['id'],
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
        $this->assertDatabaseMissing('user_bookmarks', [
            'user_id' => $other['id'],
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
        $this->assertDatabaseCount('user_bookmark_operations', 3);
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
