<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntitlementController
{
    /**
     * Return the authenticated account's server-calculated Plus access.
     *
     * This read contract deliberately exposes no grant/revoke operation.
     * Billing, coupon and administrative workflows must call trusted domain
     * services; a client request can never create an entitlement.
     */
    public function show(Request $request): JsonResponse
    {
        $serverTime = now('UTC');
        $entitlement = $request->user()
            ->entitlements()
            ->where('code', 'ad_free')
            ->first();

        $isActive = $entitlement !== null
            && $entitlement->revoked_at === null
            && $entitlement->starts_at->lessThanOrEqualTo($serverTime)
            && (
                $entitlement->expires_at === null
                || $entitlement->expires_at->greaterThan($serverTime)
            );

        return response()->json([
            'success' => true,
            'entitlements' => [
                'ad_free' => $isActive,
                'starts_at' => $entitlement?->starts_at?->toISOString(),
                'expires_at' => $entitlement?->expires_at?->toISOString(),
                'revision' => (int) ($entitlement?->revision ?? 0),
            ],
            'server_time' => $serverTime->toISOString(),
            'refresh_after_seconds' => 300,
        ]);
    }
}
