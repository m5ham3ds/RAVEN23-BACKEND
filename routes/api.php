<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
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
    Route::get('/users/check-username', [AuthController::class, 'usernameAvailable'])->middleware('throttle:auth-username-check');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/user/profile', [AuthController::class, 'profile']);
        Route::put('/user/profile', [AuthController::class, 'updateProfile']);
        Route::post('/user/change-email', [AuthController::class, 'changeEmail']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);
        Route::delete('/user/account', [AuthController::class, 'deleteAccount']);
    });
});
