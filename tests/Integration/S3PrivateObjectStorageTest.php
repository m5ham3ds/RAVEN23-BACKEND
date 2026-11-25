<?php

namespace Tests\Integration;

use App\Contracts\Storage\PrivateObjectStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class S3PrivateObjectStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RAVEN23_S3_INTEGRATION_TESTS') !== 'true') {
            $this->markTestSkipped('The disposable S3-compatible integration service is not enabled.');
        }

        // Exercise config/filesystems.php with the provider-neutral environment
        // variables supplied by CI, rather than rebuilding the S3 disk in the test.
        config([
            'filesystems.private_disk' => 's3',
            'filesystems.disks.s3.visibility' => 'private',
            'filesystems.disks.s3.throw' => true,
            'filesystems.disks.s3.report' => true,
        ]);

        Storage::forgetDisk('s3');
        $this->app->forgetInstance(PrivateObjectStorage::class);
    }

    public function test_private_object_storage_contract_runs_against_an_s3_compatible_endpoint(): void
    {
        $storage = app(PrivateObjectStorage::class);
        $key = 'integration/'.Str::uuid().'.txt';
        $contents = "RAVEN23 S3-compatible integration payload\n";

        $storage->put($key, $contents);

        try {
            $this->assertTrue($storage->exists($key));
            $this->assertSame($contents, $storage->get($key));
            $this->assertSame('private', Storage::disk('s3')->getVisibility($key));

            $objects = iterator_to_array($storage->listObjects('integration'));
            $matching = array_values(array_filter(
                $objects,
                static fn (array $object): bool => $object['key'] === $key,
            ));

            $this->assertCount(1, $matching);
            $this->assertGreaterThan(0, $matching[0]['last_modified']);

            $storage->delete($key);
            $this->assertFalse($storage->exists($key));
            $this->assertNull($storage->get($key));

            // Repeated deletes must remain safe when the object is already gone.
            $storage->delete($key);
            $this->assertFalse($storage->exists($key));
        } finally {
            $storage->delete($key);
        }
    }
}
