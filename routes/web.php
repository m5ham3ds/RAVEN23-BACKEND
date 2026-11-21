<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', static function () {
    return response()->json([
        'success' => true,
        'service' => 'raven23-api',
    ]);
});

Route::get('/ready', [HealthController::class, 'readiness']);
