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

        $bucket = (string) (getenv('AWS_BUCKET') ?: 'raven23-ci');
        $endpoint = (string) (getenv('AWS_ENDPOINT') ?: 'http://127.0.0.1:9090');

        config([
            'filesystems.private_disk' => 's3',
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'key' => (string) (getenv('AWS_ACCESS_KEY_ID') ?: 'foo'),
                'secret' => (string) (getenv('AWS_SECRET_ACCESS_KEY') ?: 'bar'),
                'region' => (string) (getenv('AWS_DEFAULT_REGION') ?: 'us-east-1'),
                'bucket' => $bucket,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
                'throw' => true,
                'report' => true,
            ],
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
