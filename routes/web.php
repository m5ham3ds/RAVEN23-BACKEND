<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', static function () {
    return response()->json([
        'success' => true,
        'service' => 'raven23-api',
    ]);
});

Route::get('/ready', [HealthController::class, 'readiness'])
    // Readiness must remain reachable when PostgreSQL is unavailable; the
    // production session store is database-backed and must not run first.
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class]);
