<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class BillingCatalogController
{
    /**
     * Public, provider-neutral catalog contract.
     *
     * No plan, price, duration, wallet limit or purchase permission is exposed
     * until the product owner approves the commercial terms.
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'purchases_enabled' => false,
            'plans' => [],
        ]);
    }
}
