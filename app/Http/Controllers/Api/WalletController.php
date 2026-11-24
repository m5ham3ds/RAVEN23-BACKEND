<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\UserWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletController
{
    /**
     * Read the authenticated account's wallet without exposing a write path.
     * The wallet is lazily initialized with a zero balance.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_guest) {
            return response()->json([
                'success' => false,
                'code' => 'wallet_unavailable_for_guest',
                'message' => 'A guest account cannot use a prepaid wallet.',
            ], 403);
        }

        $wallet = DB::transaction(function () use ($user): UserWallet {
            $owner = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $owner->wallet()->firstOrCreate([], [
                'id' => (string) Str::uuid(),
                'currency' => 'USD',
                'balance_cents' => 0,
                'spendable_cents' => 0,
                'status' => 'active',
                'frozen' => false,
                'revision' => 0,
            ]);
        });

        $serverTime = now('UTC');
        $entitlement = $user->entitlements()
            ->where('code', 'ad_free')
            ->first();
        $adFree = $entitlement !== null
            && $entitlement->revoked_at === null
            && $entitlement->starts_at !== null
            && $entitlement->starts_at->lessThanOrEqualTo($serverTime)
            && (
                $entitlement->expires_at === null
                || $entitlement->expires_at->greaterThan($serverTime)
            );

        return response()->json([
            'success' => true,
            'entitlements' => [
                'ad_free' => $adFree,
                'starts_at' => $entitlement?->starts_at?->toISOString(),
                'expires_at' => $entitlement?->expires_at?->toISOString(),
                'revision' => (int) ($entitlement?->revision ?? 0),
            ],
            'server_time' => $serverTime->toISOString(),
            'refresh_after_seconds' => 300,
            'subscription' => null,
            'wallet' => [
                'id' => (string) $wallet->getKey(),
                'currency' => $wallet->currency,
                'balance_cents' => (int) $wallet->balance_cents,
                'spendable_cents' => (int) $wallet->spendable_cents,
                'status' => $wallet->status,
                'frozen' => (bool) $wallet->frozen,
                'revision' => (int) $wallet->revision,
                // The plan price is not approved, so the server does not
                // estimate how many periods the current balance could buy.
                'periods_fundable' => 0,
            ],
            'pending_topups' => [],
        ]);
    }

    /**
     * Return a stable, owner-scoped cursor page of immutable wallet entries.
     */
    public function transactions(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet()->first();

        if ($wallet === null) {
            return response()->json([
                'success' => true,
                'transactions' => [],
                'next_cursor' => null,
            ]);
        }

        $page = $wallet->transactions()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->cursorPaginate(30);

        return response()->json([
            'success' => true,
            'transactions' => $page->getCollection()->map(static function ($transaction): array {
                $hasPeriod = $transaction->period_starts_at !== null
                    || $transaction->period_ends_at !== null;

                return [
                    'id' => (string) $transaction->getKey(),
                    'type' => $transaction->type,
                    'amount_cents' => (int) $transaction->amount_cents,
                    'balance_after_cents' => (int) $transaction->balance_after_cents,
                    'reference' => $transaction->reference,
                    'period' => $hasPeriod ? [
                        'starts_at' => $transaction->period_starts_at?->toISOString(),
                        'ends_at' => $transaction->period_ends_at?->toISOString(),
                    ] : null,
                    'created_at' => $transaction->recorded_at?->toISOString(),
                ];
            })->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
