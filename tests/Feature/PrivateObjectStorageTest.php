<?php

namespace Tests\Feature;

use App\Contracts\Storage\PrivateObjectStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class PrivateObjectStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_media');
        config(['filesystems.private_disk' => 'private_media']);
        $this->app->forgetInstance(PrivateObjectStorage::class);
    }

    public function test_private_storage_binding_uses_the_configured_private_filesystem_disk(): void
    {
        $storage = app(PrivateObjectStorage::class);

        $storage->put('users/42/avatar.webp', 'private-image-bytes');

        Storage::disk('private_media')->assertExists('users/42/avatar.webp');
        $this->assertSame('private-image-bytes', $storage->get('users/42/avatar.webp'));
        $this->assertTrue($storage->exists('users/42/avatar.webp'));

        $storage->delete('users/42/avatar.webp');

        Storage::disk('private_media')->assertMissing('users/42/avatar.webp');
        $this->assertNull($storage->get('users/42/avatar.webp'));
        $this->assertFalse($storage->exists('users/42/avatar.webp'));
    }

    public function test_private_storage_streams_object_keys_with_last_modified_timestamps(): void
    {
        Storage::disk('private_media')->put('users/42/media/avatar.webp', 'private-avatar');

        $objects = iterator_to_array(app(PrivateObjectStorage::class)->listObjects('users'));

        $this->assertCount(1, $objects);
        $this->assertSame('users/42/media/avatar.webp', $objects[0]['key']);
        $this->assertIsInt($objects[0]['last_modified']);
        $this->assertGreaterThan(0, $objects[0]['last_modified']);
    }

    public function test_private_storage_rejects_empty_traversal_and_nonportable_object_keys(): void
    {
        $storage = app(PrivateObjectStorage::class);

        foreach (['', '../outside.txt', 'users/../outside.txt', '/users/avatar.webp', 'users//avatar.webp', 'users\\avatar.webp', 'users/avátar.webp'] as $key) {
            try {
                $storage->put($key, 'must-not-be-written');
                $this->fail('An invalid private object key was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        Storage::disk('private_media')->assertMissing('outside.txt');
    }
}
