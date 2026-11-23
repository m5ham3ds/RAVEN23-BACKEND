<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookmarkSyncController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\RecentlyWatchedSyncController;
use App\Http\Controllers\Api\PlaylistSyncController;
use App\Http\Controllers\Api\PreferenceSyncController;
use App\Http\Controllers\Api\WellnessSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Readiness includes PostgreSQL and required auth-schema checks.
    // /up remains an application-only liveness endpoint.
    Route::get('/health', [HealthController::class, 'apiHealth']);

    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/auth/google', [AuthController::class, 'google'])->middleware('throttle:auth-google');
    Route::post('/auth/guest', [AuthController::class, 'guest'])->middleware('throttle:auth-guest');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:auth-email-verification');
    Route::get('/users/check-username', [AuthController::class, 'usernameAvailable'])->middleware('throttle:auth-username-check');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/email/verification-notification', [AuthController::class, 'sendEmailVerification'])->middleware('throttle:auth-email-verification');
        Route::get('/user/devices', [AuthController::class, 'devices'])->middleware('throttle:auth-device-management');
        Route::delete('/user/devices', [AuthController::class, 'revokeAllDevices'])->middleware('throttle:auth-device-management');
        Route::delete('/user/devices/{tokenId}', [AuthController::class, 'revokeDevice'])->whereNumber('tokenId')->middleware('throttle:auth-device-management');
        Route::get('/user/profile', [AuthController::class, 'profile']);
        Route::put('/user/profile', [AuthController::class, 'updateProfile']);
        Route::get('/user/preferences', [AuthController::class, 'preferences']);
        Route::put('/user/preferences', [AuthController::class, 'updatePreferences']);
        Route::get('/user/data-export', [AuthController::class, 'exportUserData']);
        Route::post('/user/change-email', [AuthController::class, 'changeEmail']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);
        Route::delete('/user/account', [AuthController::class, 'deleteAccount']);

        Route::post('/sync/bookmarks', [BookmarkSyncController::class, 'sync']);
        Route::delete('/sync/bookmarks/{type}/{id}', [BookmarkSyncController::class, 'delete'])
            ->where('type', 'movie|tv')
            ->whereNumber('id');
        Route::post('/sync/recently-watched', [RecentlyWatchedSyncController::class, 'sync']);
        Route::post('/sync/playlists', [PlaylistSyncController::class, 'sync']);
        Route::post('/sync/preferences', [PreferenceSyncController::class, 'sync']);
        Route::post('/sync/wellness', [WellnessSyncController::class, 'sync']);
    });
});
