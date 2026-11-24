<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationOutboxStatus extends Command
{
    protected $signature = 'notifications:outbox-status {--json : Emit machine-readable aggregate counts.}';

    protected $description = 'Report notification outbox health without exposing user or message data.';

    private const CLAIM_LEASE_SECONDS = 60;

    public function handle(): int
    {
        $now = now();
        $groups = DB::table('notification_outbox')
            ->select('notification_type', 'status')
            ->selectRaw('COUNT(*) as record_count')
            ->groupBy('notification_type', 'status')
            ->orderBy('notification_type')
            ->orderBy('status')
            ->get()
            ->map(static fn ($row): array => [
                'notification_type' => (string) $row->notification_type,
                'status' => (string) $row->status,
                'record_count' => (int) $row->record_count,
            ])
            ->all();

        $pendingDueQuery = DB::table('notification_outbox')
            ->where('status', 'pending')
            ->where('available_at', '<=', $now);
        $pendingDue = (clone $pendingDueQuery)->count();
        $oldestDue = (clone $pendingDueQuery)->orderBy('created_at')->value('created_at');
        $pendingScheduled = DB::table('notification_outbox')
            ->where('status', 'pending')
            ->where('available_at', '>', $now)
            ->count();
        $processing = DB::table('notification_outbox')
            ->where('status', 'processing')
            ->count();
        $claimCutoff = $now->copy()->subSeconds(self::CLAIM_LEASE_SECONDS);
        $expiredClaims = DB::table('notification_outbox')
            ->where('status', 'processing')
            ->where(static function ($query) use ($claimCutoff): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', $claimCutoff);
            })
            ->count();
        $failed = DB::table('notification_outbox')
            ->where('status', 'failed')
            ->count();
        $sent = DB::table('notification_outbox')
            ->where('status', 'sent')
            ->count();

        $oldestDueAge = $oldestDue === null
            ? null
            : max(0, $now->getTimestamp() - Carbon::parse($oldestDue)->getTimestamp());

        $summary = [
            'checked_at_utc' => $now->copy()->utc()->toIso8601String(),
            'by_type_and_status' => $groups,
            'pending_due_count' => $pendingDue,
            'pending_scheduled_count' => $pendingScheduled,
            'processing_count' => $processing,
            'expired_claim_count' => $expiredClaims,
            'failed_count' => $failed,
            'sent_count' => $sent,
            'oldest_due_pending_age_seconds' => $oldestDueAge,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Notification type', 'Status', 'Records'],
            array_map(
                static fn (array $group): array => [
                    $group['notification_type'],
                    $group['status'],
                    $group['record_count'],
                ],
                $groups,
            ),
        );
        $this->line(sprintf(
            'pending_due=%d pending_scheduled=%d processing=%d expired_claims=%d failed=%d sent=%d oldest_due_age_seconds=%s',
            $pendingDue,
            $pendingScheduled,
            $processing,
            $expiredClaims,
            $failed,
            $sent,
            $oldestDueAge === null ? 'n/a' : (string) $oldestDueAge,
        ));

        return self::SUCCESS;
    }
}
