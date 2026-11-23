<?php

namespace App\Contracts\Storage;

/**
 * Private object storage boundary for user-owned files.
 *
 * Implementations must keep objects private by default. Callers are responsible
 * for generating opaque, server-controlled keys rather than accepting paths
 * from clients.
 */
interface PrivateObjectStorage
{
    public function put(string $key, string $contents): void;

    public function get(string $key): ?string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    /**
     * @return iterable<array{key: string, last_modified: int}>
     */
    public function listObjects(string $prefix): iterable;
}
