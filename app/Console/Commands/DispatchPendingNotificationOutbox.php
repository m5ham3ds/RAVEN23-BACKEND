<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotificationOutboxJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

class DispatchPendingNotificationOutbox extends Command
{
    protected $signature = 'notifications:outbox-dispatch {--limit=100}';

    protected $description = 'Dispatch due notification outbox records to the encrypted mail queue.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 500) {
            $this->error('The outbox dispatch limit must be between 1 and 500.');

            return self::INVALID;
        }

        $cutoff = now()->subSeconds(max(60, (int) config('notifications.outbox_redispatch_after_seconds', 300)));
        $ids = DB::table('notification_outbox')
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->where(static function ($query) use ($cutoff): void {
                $query->whereNull('last_dispatched_at')
                    ->orWhere('last_dispatched_at', '<=', $cutoff);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;
        foreach ($ids as $id) {
            $reserved = DB::transaction(function () use ($id, $cutoff): bool {
                $record = DB::table('notification_outbox')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $record === null
                    || $record->status !== 'pending'
                    || now()->lessThan($record->available_at)
                    || ($record->last_dispatched_at !== null && $record->last_dispatched_at > $cutoff)
                ) {
                    return false;
                }

                DB::table('notification_outbox')
                    ->where('id', $id)
                    ->update(['last_dispatched_at' => now(), 'updated_at' => now()]);

                return true;
            });

            if (! $reserved) {
                continue;
            }

            try {
                Queue::pushOn('mail', new DeliverNotificationOutboxJob((string) $id));
                $dispatched++;
            } catch (Throwable $exception) {
                DB::table('notification_outbox')
                    ->where('id', $id)
                    ->where('status', 'pending')
                    ->update(['last_dispatched_at' => null, 'updated_at' => now()]);

                Log::warning('RAVEN23 notification outbox relay dispatch failed', [
                    'exception_type' => get_class($exception),
                ]);
            }
        }

        $this->info(sprintf('Dispatched %d of %d due notification outbox record(s).', $dispatched, $ids->count()));

        return self::SUCCESS;
    }
}
