<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserEntitlement;
use App\Services\Entitlements\SubscriptionEventLedger;
use Illuminate\Console\Command;

class ExpireDueEntitlements extends Command
{
    protected $signature = 'subscriptions:expire-due
        {--apply : Record expiry events and project expired entitlements.}
        {--limit=500 : Maximum number of due entitlements to inspect (1-5000).}';

    protected $description = 'Preview or record due entitlement expirations through the append-only ledger.';

    public function handle(SubscriptionEventLedger $ledger): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 5000) {
            $this->error('The limit must be between 1 and 5000.');

            return self::INVALID;
        }

        $now = now('UTC');
        $ids = UserEntitlement::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('starts_at', '<=', $now)
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if (! $this->option('apply')) {
            $this->info(sprintf(
                'dry_run=true candidates=%d limit=%d; no records were changed.',
                $ids->count(),
                $limit,
            ));

            return self::SUCCESS;
        }

        $applied = 0;
        $stale = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $entitlement = UserEntitlement::query()->find($id);
            if (
                $entitlement === null
                || $entitlement->revoked_at !== null
                || $entitlement->expires_at === null
                || $entitlement->starts_at->isFuture()
                || $entitlement->expires_at->isFuture()
            ) {
                $skipped++;

                continue;
            }

            $user = User::query()->find($entitlement->user_id);
            if ($user === null) {
                $skipped++;

                continue;
            }

            $event = $ledger->record(
                $user,
                'expired',
                $entitlement->code,
                'internal',
                sprintf('entitlement-expiry:%d:%d', $entitlement->getKey(), $entitlement->revision),
                $entitlement->expires_at,
                $entitlement->starts_at,
                $entitlement->expires_at,
                sprintf('user-entitlement:%d', $entitlement->getKey()),
                'period_elapsed',
            );

            if ($event->projection_status === 'applied') {
                $applied++;
            } else {
                $stale++;
            }
        }

        $this->info(sprintf(
            'dry_run=false applied=%d stale=%d skipped=%d candidates=%d limit=%d',
            $applied,
            $stale,
            $skipped,
            $ids->count(),
            $limit,
        ));

        return self::SUCCESS;
    }
}
