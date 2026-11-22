<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Readiness includes PostgreSQL and required auth-schema checks.
    // /up remains an application-only liveness endpoint.
    Route::get('/health', [HealthController::class, 'apiHealth']);

    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/google', [AuthController::class, 'google'])->middleware('throttle:10,1');
    Route::post('/auth/guest', [AuthController::class, 'guest'])->middleware('throttle:10,1');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');
    Route::get('/users/check-username', [AuthController::class, 'usernameAvailable'])->middleware('throttle:30,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/user/profile', [AuthController::class, 'profile']);
        Route::put('/user/profile', [AuthController::class, 'updateProfile']);
        Route::post('/user/change-email', [AuthController::class, 'changeEmail']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);
        Route::delete('/user/account', [AuthController::class, 'deleteAccount']);
    });
});
