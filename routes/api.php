<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        try {
            DB::select('select 1');

            $requiredColumns = [
                'users' => [
                    'id', 'name', 'email', 'password', 'username',
                    'profile_id', 'provider', 'google_id', 'is_verified',
                    'is_account_verified', 'is_guest', 'created_at', 'updated_at',
                ],
                'personal_access_tokens' => [
                    'id', 'tokenable_type', 'tokenable_id', 'name', 'token',
                    'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
                ],
            ];

            $missing = [];
            foreach ($requiredColumns as $table => $columns) {
                if (!Schema::hasTable($table)) {
                    $missing[] = $table;
                    continue;
                }

                $actual = Schema::getColumnListing($table);
                foreach ($columns as $column) {
                    if (!in_array($column, $actual, true)) {
                        $missing[] = "{$table}.{$column}";
                    }
                }
            }

            if ($missing !== []) {
                return response()->json([
                    'success' => false,
                    'service' => 'raven23-api',
                    'database' => 'connected',
                    'schema' => 'incomplete',
                    'missing' => $missing,
                ], 503);
            }

            return response()->json([
                'success' => true,
                'service' => 'raven23-api',
                'version' => '1',
                'database' => 'connected',
                'schema' => 'ready',
                'google_auth' => filled(config('services.google.client_id'))
                    ? 'configured'
                    : 'not_configured',
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
