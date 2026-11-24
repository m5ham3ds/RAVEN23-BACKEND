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
    protected $signature = 'notifications:outbox-dispatch {--limit=}';

    protected $description = 'Dispatch due notification outbox records and recover expired worker claims.';

    private const CLAIM_LEASE_SECONDS = 60;

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?: config('notifications.outbox_dispatch_batch', 100));
        if ($limit < 1 || $limit > 500) {
            $this->error('The outbox dispatch limit must be between 1 and 500.');

            return self::INVALID;
        }

        $cutoff = now()->subSeconds(max(60, (int) config('notifications.outbox_redispatch_after_seconds', 300)));
        $claimCutoff = now()->subSeconds(self::CLAIM_LEASE_SECONDS);
        $ids = DB::table('notification_outbox')
            ->where(static function ($query) use ($cutoff, $claimCutoff): void {
                $query->where(static function ($pending) use ($cutoff): void {
                    $pending->where('status', 'pending')
                        ->where('available_at', '<=', now())
                        ->where(static function ($dispatched) use ($cutoff): void {
                            $dispatched->whereNull('last_dispatched_at')
                                ->orWhere('last_dispatched_at', '<=', $cutoff);
                        });
                })->orWhere(static function ($processing) use ($claimCutoff): void {
                    $processing->where('status', 'processing')
                        ->where(static function ($claimed) use ($claimCutoff): void {
                            $claimed->whereNull('claimed_at')
                                ->orWhere('claimed_at', '<=', $claimCutoff);
                        });
                });
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;
        foreach ($ids as $id) {
            $queue = DB::transaction(function () use ($id, $cutoff, $claimCutoff): ?string {
                $record = DB::table('notification_outbox')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if ($record === null) {
                    return null;
                }

                if ($record->status === 'pending') {
                    if (
                        now()->lessThan($record->available_at)
                        || ($record->last_dispatched_at !== null && $record->last_dispatched_at > $cutoff)
                    ) {
                        return false;
                    }
                } elseif ($record->status === 'processing') {
                    if ($record->claimed_at !== null && $record->claimed_at > $claimCutoff) {
                        return false;
                    }
                } else {
                    return false;
                }

                DB::table('notification_outbox')
                    ->where('id', $id)
                    ->update([
                        'status' => 'pending',
                        'claimed_at' => null,
                        'last_dispatched_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $record->notification_type === 'push_delivery' ? 'push' : 'mail';
            });

            if ($queue === null) {
                continue;
            }

            try {
                Queue::pushOn($queue, new DeliverNotificationOutboxJob((string) $id, $queue));
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
