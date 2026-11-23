<?php

namespace App\Services\Storage;

use App\Contracts\Storage\PrivateObjectStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use RuntimeException;

final class FilesystemPrivateObjectStorage implements PrivateObjectStorage
{
    public function __construct(private readonly FilesystemAdapter $filesystem) {}

    public function put(string $key, string $contents): void
    {
        $this->assertValidKey($key);

        if (! $this->filesystem->put($key, $contents, ['visibility' => 'private'])) {
            throw new RuntimeException('The private object could not be stored.');
        }
    }

    public function get(string $key): ?string
    {
        $this->assertValidKey($key);

        if (! $this->filesystem->exists($key)) {
            return null;
        }

        $contents = $this->filesystem->get($key);
        if (! is_string($contents)) {
            throw new RuntimeException('The private object could not be read.');
        }

        return $contents;
    }

    public function exists(string $key): bool
    {
        $this->assertValidKey($key);

        return $this->filesystem->exists($key);
    }

    public function delete(string $key): void
    {
        $this->assertValidKey($key);

        if ($this->filesystem->exists($key) && ! $this->filesystem->delete($key)) {
            throw new RuntimeException('The private object could not be deleted.');
        }
    }

    /**
     * Stream object metadata under a prefix without buffering the full store.
     *
     * @return iterable<array{key: string, last_modified: int}>
     */
    public function listObjects(string $prefix): iterable
    {
        $prefix = rtrim($prefix, '/');
        $this->assertValidKey($prefix);

        foreach ($this->filesystem->getDriver()->listContents($prefix, true) as $attributes) {
            if (! $attributes->isFile()) {
                continue;
            }

            $key = $attributes->path();
            $this->assertValidKey($key);

            yield [
                'key' => $key,
                'last_modified' => (int) ($attributes->lastModified() ?? 0),
            ];
        }
    }

    private function assertValidKey(string $key): void
    {
        $segments = explode('/', $key);

        if (
            $key === ''
            || str_contains($key, '\\')
            || preg_match('/[^A-Za-z0-9._\/-]/', $key) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new InvalidArgumentException('The private object key is invalid.');
        }
    }
}
