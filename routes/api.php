<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use PDOException;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        $stage = 'database_ping';
        $requestId = (string) Str::uuid();
        $connectionName = (string) config('database.default', 'unknown');
        $connectionConfig = config('database.connections.' . $connectionName, []);
        $driver = is_array($connectionConfig) ? ($connectionConfig['driver'] ?? null) : null;

        $configuredUrl = is_array($connectionConfig)
            ? (string) ($connectionConfig['url'] ?? '')
            : '';
        $urlParts = $configuredUrl !== '' ? parse_url($configuredUrl) : [];
        $configuredHost = strtolower((string) ($urlParts['host'] ?? ''));
        $configuredHost = (string) preg_replace('/-pooler(?=\.|$)/', '', $configuredHost);
        $configuredDatabase = isset($urlParts['path'])
            ? rawurldecode(trim((string) $urlParts['path'], '/'))
            : null;

        $safeRuntime = [
            'connection' => $connectionName,
            'driver' => $driver,
            'configured_host' => $configuredHost !== '' ? $configuredHost : null,
            'configured_database' => $configuredDatabase,
            'configured_schema' => is_array($connectionConfig)
                ? ($connectionConfig['search_path'] ?? null)
                : null,
            // Render's Git metadata variable is optional; this hard-coded
            // diagnostic marker identifies the source code in this route.
            'diagnostic_version' => 'health-diag-v2',
            'render_git_commit' => substr((string) (getenv('RENDER_GIT_COMMIT') ?: 'unknown'), 0, 12),
            'instance_id' => substr((string) (getenv('RENDER_INSTANCE_ID') ?: 'unknown'), 0, 12),
        ];

        try {
            DB::connection($connectionName)->select('select 1');

            $stage = 'connection_identity';
            $identity = DB::connection($connectionName)->selectOne("
                SELECT
                    current_database() AS database_name,
                    current_schema() AS schema_name,
                    current_setting('search_path') AS search_path
            ");

            $runtime = $safeRuntime + [
                'database_name' => $identity->database_name ?? null,
                'schema_name' => $identity->schema_name ?? null,
                'search_path' => $identity->search_path ?? null,
            ];

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
                $stage = 'schema_inspection:' . $table;
                if (!Schema::connection($connectionName)->hasTable($table)) {
                    $missing[] = $table;
                    continue;
                }

                $actual = Schema::connection($connectionName)->getColumnListing($table);
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
                    'runtime_target' => $runtime,
                    'missing' => $missing,
                ], 503);
            }

            return response()->json([
                'success' => true,
                'service' => 'raven23-api',
                'version' => '1',
                'database' => 'connected',
                'schema' => 'ready',
                'runtime_target' => $runtime,
                'google_auth' => filled(config('services.google.client_id'))
                    ? 'configured'
                    : 'not_configured',
            ]);
        } catch (Throwable $exception) {
            $errorInfo = null;
            if ($exception instanceof QueryException || $exception instanceof PDOException) {
                $errorInfo = $exception->errorInfo ?? null;
            }

            $diagnostic = [
                'request_id' => $requestId,
                'stage' => $stage,
                'exception_type' => get_class($exception),
                'source_file' => basename($exception->getFile()),
                'source_line' => $exception->getLine(),
                'sql_state' => is_array($errorInfo) ? ($errorInfo[0] ?? null) : null,
                'database_error_code' => is_array($errorInfo) ? ($errorInfo[1] ?? null) : null,
                'connection' => $connectionName,
                'driver' => $driver,
                'configured_host' => $safeRuntime['configured_host'],
                'configured_database' => $configuredDatabase,
                'diagnostic_revision' => $safeRuntime['diagnostic_version'],
                'render_git_commit' => $safeRuntime['render_git_commit'],
                'instance_id' => $safeRuntime['instance_id'],
            ];

            // Deliberately omit exception message, SQL, bindings and credentials.
            Log::error('RAVEN23 health check failed', $diagnostic);

            return response()->json([
                'success' => false,
                'service' => 'raven23-api',
                'database' => 'unavailable',
                'diagnostic' => $diagnostic,
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
