<?php

namespace App\Jobs;

use App\Contracts\Storage\PrivateObjectStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeletePrivateObjectJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $objectKey)
    {
        $this->onQueue('default')->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30, 90, 180];
    }

    public function handle(PrivateObjectStorage $storage): void
    {
        // Missing objects are treated as successful idempotent deletions.
        $storage->delete($this->objectKey);
    }
}
