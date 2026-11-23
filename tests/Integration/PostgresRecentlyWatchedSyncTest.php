<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresRecentlyWatchedSyncTest extends TestCase
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
            'name' => 'RAVEN23 PostgreSQL Recent',
            'email' => 'raven-recent-'.$suffix.'@example.test',
            'username' => 'raven_recent_'.$suffix,
            'password' => 'Raven-Recent-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL recent sync test')->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);

        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function test_postgresql_owner_scoped_recent_progress_and_tombstones_use_the_restricted_app_role(): void
    {
        $owner = $this->createAccount();
        $baseStamp = $this->nowMs() - 10_000;
        $deletedStamp = $baseStamp + 1_000;
        $other = $this->createAccount();

        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->nowMs(),
                'movies' => [[
                    'id' => 550,
                    'movie_id' => 550,
                    'title' => 'Owner PostgreSQL movie',
                    'elapsed' => 600,
                    'remaining' => 300,
                    'updated_at_utc' => $baseStamp,
                    'deleted_at_utc' => null,
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'movies')
            ->assertJsonPath('movies.0.title', 'Owner PostgreSQL movie');

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 0,
                'client_time_utc' => $this->nowMs(),
            ])
            ->assertOk()
            ->assertJsonCount(0, 'movies');

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->postJson('/api/v1/sync/recently-watched', [
                'since_revision' => 1,
                'client_time_utc' => $this->nowMs(),
                'movies' => [[
                    'id' => 550,
                    'movie_id' => 550,
                    'title' => 'Owner PostgreSQL movie',
                    'elapsed' => 600,
                    'remaining' => 300,
                    'updated_at_utc' => $deletedStamp,
                    'deleted_at_utc' => $deletedStamp,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('movies.0.deleted_at_utc', $deletedStamp);

        $this->assertDatabaseHas('user_recent_media', [
            'user_id' => $owner['id'],
            'media_type' => 'movie',
            'media_id' => 550,
            'deleted_at_utc' => $deletedStamp,
        ]);
        $this->assertDatabaseMissing('user_recent_media', [
            'user_id' => $other['id'],
            'media_type' => 'movie',
            'media_id' => 550,
        ]);
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
