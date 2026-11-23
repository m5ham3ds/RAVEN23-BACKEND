<?php

namespace Tests\Integration;

use App\Contracts\Storage\PrivateObjectStorage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PostgresUserMediaTest extends TestCase
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
            'filesystems.private_disk' => 'private_media',
            'filesystems.private_media_url_ttl_minutes' => 5,
        ]);
        DB::purge('pgsql');
        URL::forceRootUrl('http://localhost');
        Storage::fake('private_media');
        $this->app->forgetInstance(PrivateObjectStorage::class);

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        DB::connection()->selectOne('select 1');
    }

    private function createAccount(string $label): array
    {
        $suffix = bin2hex(random_bytes(6));
        $user = User::create([
            'name' => 'RAVEN23 PostgreSQL '.$label,
            'email' => 'raven-media-'.$suffix.'@example.test',
            'username' => 'media_pg_'.$suffix,
            'password' => 'Raven-Media-Postgres-42!',
        ]);
        $plainText = $user->createToken('PostgreSQL media '.$label)->plainTextToken;
        $stored = PersonalAccessToken::findToken($plainText);

        $this->createdUsers[] = (int) $user->getKey();
        $this->createdTokens[] = (int) $stored->getKey();

        return ['id' => (int) $user->getKey(), 'token' => $plainText];
    }

    public function test_postgresql_media_metadata_is_owner_scoped_and_signed_downloads_expire(): void
    {
        $owner = $this->createAccount('owner');
        $other = $this->createAccount('other');
        $contents = "PostgreSQL private media\n";

        $uploaded = $this->withToken($owner['token'])
            ->post('/api/v1/user/media', [
                'purpose' => 'attachment',
                'file' => UploadedFile::fake()->createWithContent('private-notes.txt', $contents),
            ])
            ->assertCreated()
            ->assertJsonPath('media.purpose', 'attachment')
            ->assertJsonPath('media.mime_type', 'text/plain');

        $mediaId = (string) $uploaded->json('media.id');
        $objectKey = (string) DB::table('user_media')->where('id', $mediaId)->value('object_key');
        $protectedUrl = (string) $uploaded->json('media.download_url');
        $temporaryUrl = (string) $uploaded->json('media.temporary_download_url');

        $this->assertDatabaseHas('user_media', [
            'id' => $mediaId,
            'user_id' => $owner['id'],
            'purpose' => 'attachment',
            'object_key' => $objectKey,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
        ]);
        Storage::disk('private_media')->assertExists($objectKey);

        Auth::forgetGuards();
        $download = $this->get($temporaryUrl)
            ->assertOk()
            ->assertContent($contents)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = (string) $download->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->get($protectedUrl)
            ->assertNotFound();

        $tamperedUrl = str_replace(
            '/user/media/'.$owner['id'].'/',
            '/user/media/'.$other['id'].'/',
            $temporaryUrl,
        );
        $this->get($tamperedUrl)->assertForbidden();

        $this->travel(6)->minutes();
        $this->get($temporaryUrl)->assertForbidden();
    }

    public function test_postgresql_account_media_quota_rejects_additional_files(): void
    {
        $owner = $this->createAccount('quota');
        config(['filesystems.user_media_quota_bytes' => 4]);

        $this->withToken($owner['token'])
            ->post('/api/v1/user/media', [
                'purpose' => 'attachment',
                'file' => UploadedFile::fake()->createWithContent('quota.txt', 'four'),
            ])
            ->assertCreated();

        Auth::forgetGuards();
        $this->withToken($owner['token'])
            ->post('/api/v1/user/media', [
                'purpose' => 'attachment',
                'file' => UploadedFile::fake()->createWithContent('overflow.txt', 'x'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('user_media', 1);
        $objectKey = (string) DB::table('user_media')->value('object_key');
        Storage::disk('private_media')->assertExists($objectKey);
        $this->assertCount(1, Storage::disk('private_media')->allFiles());
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTokens) as $tokenId) {
            try {
                DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
            } catch (\Throwable) {
                // Preserve the original test failure; cleanup is best-effort.
            }
        }

        foreach (array_reverse($this->createdUsers) as $userId) {
            try {
                DB::table('users')->where('id', $userId)->delete();
            } catch (\Throwable) {
                // Foreign keys cascade user media metadata.
            }
        }

        Auth::forgetGuards();
        parent::tearDown();
    }
}
