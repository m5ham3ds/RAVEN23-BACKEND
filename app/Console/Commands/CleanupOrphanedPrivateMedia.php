<?php

namespace App\Console\Commands;

use App\Contracts\Storage\PrivateObjectStorage;
use App\Jobs\DeletePrivateObjectJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupOrphanedPrivateMedia extends Command
{
    protected $signature = 'media:cleanup-orphans
        {--apply : Queue deletion jobs for objects with no metadata}
        {--older-than= : Minimum object age in seconds (cannot be lower than configured grace)}
        {--limit= : Maximum number of orphan objects to queue}';

    protected $description = 'Find or queue deletion of old private media objects with no owner metadata';

    public function handle(PrivateObjectStorage $storage): int
    {
        $graceSeconds = max(86400, (int) config('filesystems.private_media_orphan_grace_seconds', 604800));
        $olderThan = $this->option('older-than') === null
            ? $graceSeconds
            : filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        if (! is_int($olderThan) || $olderThan < $graceSeconds) {
            $this->error('The requested age must be an integer no shorter than the configured grace period.');

            return self::FAILURE;
        }

        $configuredLimit = (int) config('filesystems.private_media_orphan_batch_limit', 500);
        $limit = $this->option('limit') === null
            ? $configuredLimit
            : filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > min(5000, max(1, $configuredLimit))) {
            $this->error('The requested limit must be within the configured cleanup batch limit.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && ! (bool) config('filesystems.private_media_orphan_cleanup_enabled', false)) {
            $this->error('Applying orphan cleanup is disabled. Enable RAVEN23_PRIVATE_MEDIA_ORPHAN_CLEANUP_ENABLED explicitly first.');

            return self::FAILURE;
        }

        $cutoff = now()->subSeconds($olderThan)->getTimestamp();
        $orphans = [];
        $batch = [];
        $inspected = 0;

        foreach ($storage->listObjects('users') as $object) {
            $key = (string) ($object['key'] ?? '');
            $lastModified = (int) ($object['last_modified'] ?? 0);

            if (
                preg_match('#^users/[0-9]+/media/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\.(?:jpg|jpeg|png|webp|pdf|txt)$#D', $key) !== 1
                || $lastModified < 1
                || $lastModified > $cutoff
            ) {
                continue;
            }

            $batch[] = $key;
            $inspected++;

            if (count($batch) >= 250) {
                $orphans = [...$orphans, ...$this->unreferencedKeys($batch)];
                $batch = [];

                if (count($orphans) >= $limit) {
                    break;
                }
            }
        }

        if ($batch !== [] && count($orphans) < $limit) {
            $orphans = [...$orphans, ...$this->unreferencedKeys($batch)];
        }

        $orphans = array_slice($orphans, 0, $limit);

        if (! $apply) {
            $this->info(sprintf(
                'Dry run: inspected %d old media objects and found %d orphan candidates; no jobs were dispatched.',
                $inspected,
                count($orphans),
            ));

            return self::SUCCESS;
        }

        foreach ($orphans as $key) {
            DeletePrivateObjectJob::dispatch($key);
        }

        $this->info(sprintf(
            'Queued %d orphan media deletion jobs after the configured grace period.',
            count($orphans),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function unreferencedKeys(array $keys): array
    {
        $referenced = DB::table('user_media')
            ->whereIn('object_key', $keys)
            ->pluck('object_key')
            ->all();
        $lookup = array_fill_keys($referenced, true);

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ! isset($lookup[$key]),
        ));
    }
}
