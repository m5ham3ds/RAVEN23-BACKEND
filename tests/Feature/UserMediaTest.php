<?php

namespace Tests\Feature;

use App\Contracts\Storage\PrivateObjectStorage;
use App\Jobs\DeletePrivateObjectJob;
use App\Models\UserMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'cache.default' => 'array',
            'mail.default' => 'array',
            'queue.default' => 'database',
            'filesystems.private_disk' => 'private_media',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Storage::fake('private_media');
        $this->app->forgetInstance(PrivateObjectStorage::class);

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function registerAccount(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $email = 'media-'.$suffix.'@example.test';
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'RAVEN23 Media Test',
            'email' => $email,
            'username' => 'media_'.$suffix,
            'password' => 'Raven-Media-Test-Password-42!',
        ])->assertCreated();

        return [
            'id' => (int) $response->json('user.id'),
            'token' => (string) $response->json('token'),
            'email' => $email,
        ];
    }

    private function pngBytes(): string
    {
        $chunk = static function (string $type, string $payload): string {
            return pack('N', strlen($payload))
                .$type
                .$payload
                .pack('N', crc32($type.$payload));
        };

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNC5', 1, 1, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress("\x00\x20\x40\x60"))
            .$chunk('IEND', '');
    }

    private function uploadAvatar(string $token): array
    {
        $bytes = $this->pngBytes();
        $response = $this->withToken($token)
            ->post('/api/v1/user/media', [
                'purpose' => 'avatar',
                'file' => UploadedFile::fake()->createWithContent('avatar.png', $bytes),
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('media.purpose', 'avatar')
            ->assertJsonPath('media.mime_type', 'image/png')
            ->assertJsonPath('media.size_bytes', strlen($bytes));

        return [
            'id' => (string) $response->json('media.id'),
            'url' => (string) $response->json('media.download_url'),
            'bytes' => $bytes,
            'media' => UserMedia::query()->findOrFail($response->json('media.id')),
        ];
    }

    public function test_authenticated_owner_can_upload_and_download_a_private_avatar(): void
    {
        $account = $this->registerAccount();
        $uploaded = $this->uploadAvatar($account['token']);

        $this->assertDatabaseHas('user_media', [
            'id' => $uploaded['id'],
            'user_id' => $account['id'],
            'purpose' => 'avatar',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($uploaded['bytes']),
            'checksum_sha256' => hash('sha256', $uploaded['bytes']),
        ]);
        Storage::disk('private_media')->assertExists($uploaded['media']->object_key);

        $download = $this->withToken($account['token'])
            ->get($uploaded['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="avatar.png"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $download->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertSame($uploaded['bytes'], $download->getContent());
    }

    public function test_upload_rejects_unsupported_executable_files_and_oversized_attachments(): void
    {
        $account = $this->registerAccount();

        $this->withToken($account['token'])
            ->post('/api/v1/user/media', [
                'purpose' => 'attachment',
                'file' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "no";'),
            ])
            ->assertUnprocessable();

        $this->withToken($account['token'])
            ->post('/api/v1/user/media', [
                'purpose' => 'attachment',
                'file' => UploadedFile::fake()->createWithContent('large.txt', str_repeat('x', 10 * 1024 * 1024 + 1)),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('user_media', 0);
    }

    public function test_user_cannot_read_or_delete_another_accounts_media(): void
    {
        $owner = $this->registerAccount();
        $other = $this->registerAccount();
        $uploaded = $this->uploadAvatar($owner['token']);

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->getJson('/api/v1/user/media/'.$uploaded['id'])
            ->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($other['token'])
            ->deleteJson('/api/v1/user/media/'.$uploaded['id'])
            ->assertNotFound();

        $this->assertDatabaseHas('user_media', ['id' => $uploaded['id'], 'user_id' => $owner['id']]);
        Storage::disk('private_media')->assertExists($uploaded['media']->object_key);
    }

    public function test_media_upload_and_download_require_authentication(): void
    {
        $this->withHeader('Accept', 'application/json')->post('/api/v1/user/media', [
            'purpose' => 'attachment',
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'private notes'),
        ])->assertUnauthorized();

        $this->getJson('/api/v1/user/media/00000000-0000-4000-8000-000000000000')
            ->assertUnauthorized();
    }

    public function test_attachment_upload_is_private_and_metadata_is_included_in_account_export(): void
    {
        $account = $this->registerAccount();
        $response = $this->withToken($account['token'])
            ->post('/api/v1/user/media', [
                'purpose' => 'attachment',
                'file' => UploadedFile::fake()->createWithContent('notes.txt', "private notes\n"),
            ])
            ->assertCreated()
            ->assertJsonPath('media.purpose', 'attachment')
            ->assertJsonPath('media.mime_type', 'text/plain');

        $media = UserMedia::query()->findOrFail($response->json('media.id'));
        Storage::disk('private_media')->assertExists($media->object_key);

        Auth::forgetGuards();
        $export = $this->withToken($account['token'])
            ->getJson('/api/v1/user/data-export')
            ->assertOk()
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.id', $media->getKey())
            ->assertJsonPath('data.media.0.original_name', 'notes.txt');

        $this->assertStringNotContainsString($media->object_key, $export->getContent());
    }

    public function test_file_deletion_is_owner_scoped_and_runs_as_an_encrypted_after_commit_job(): void
    {
        $account = $this->registerAccount();
        $uploaded = $this->uploadAvatar($account['token']);
        $objectKey = $uploaded['media']->object_key;

        Queue::fake();
        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->deleteJson('/api/v1/user/media/'.$uploaded['id'])
            ->assertAccepted()
            ->assertJsonPath('deletion_queued', true);

        $this->assertDatabaseMissing('user_media', ['id' => $uploaded['id']]);
        Storage::disk('private_media')->assertExists($objectKey);
        Queue::assertPushed(DeletePrivateObjectJob::class, function (DeletePrivateObjectJob $job) use ($objectKey): bool {
            return $job->objectKey === $objectKey
                && $job instanceof \Illuminate\Contracts\Queue\ShouldBeEncrypted
                && $job->afterCommit;
        });

        Queue::pushed(DeletePrivateObjectJob::class)->first()->handle(app(PrivateObjectStorage::class));
        Storage::disk('private_media')->assertMissing($objectKey);
    }

    public function test_account_deletion_queues_cleanup_and_cascades_media_metadata(): void
    {
        $account = $this->registerAccount();
        $uploaded = $this->uploadAvatar($account['token']);
        $objectKey = $uploaded['media']->object_key;

        Queue::fake();
        Auth::forgetGuards();
        $this->withToken($account['token'])
            ->deleteJson('/api/v1/user/account')
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $account['id']]);
        $this->assertDatabaseMissing('user_media', ['id' => $uploaded['id']]);
        Queue::assertPushed(DeletePrivateObjectJob::class, fn (DeletePrivateObjectJob $job): bool => $job->objectKey === $objectKey);
    }

    public function test_private_storage_disk_is_not_the_public_symbolic_link_target(): void
    {
        $this->assertSame(storage_path('app/private/media'), config('filesystems.disks.private_media.root'));
        $this->assertFalse((bool) config('filesystems.disks.private_media.serve', true));
        $this->assertSame('private', config('filesystems.disks.private_media.visibility'));
        $this->assertSame(storage_path('app/public'), config('filesystems.links')[public_path('storage')]);
    }
}
