<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        try {
            DB::select('select 1');
            return response()->json([
                'success' => true,
                'service' => 'raven23-api',
                'version' => '1',
                'database' => 'connected',
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json([
                'success' => false,
                'service' => 'raven23-api',
                'database' => 'unavailable',
            ], 503);
        }
    });

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
