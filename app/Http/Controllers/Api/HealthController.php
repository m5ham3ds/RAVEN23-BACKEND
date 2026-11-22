<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthController extends Controller
{
    private const REQUIRED_COLUMNS = [
        'users' => [
            'id', 'name', 'email', 'password', 'username',
            'profile_id', 'provider', 'google_id', 'is_verified',
            'is_account_verified', 'is_guest', 'created_at', 'updated_at',
        ],
        'personal_access_tokens' => [
            'id', 'tokenable_type', 'tokenable_id', 'name', 'token',
            'abilities', 'last_used_at', 'expires_at',
            'device_id', 'device_name', 'platform',
            'created_at', 'updated_at',
        ],
        'cache' => ['key', 'value', 'expiration'],
        'cache_locks' => ['key', 'owner', 'expiration'],
        'sessions' => [
            'id', 'user_id', 'ip_address', 'user_agent',
            'payload', 'last_activity',
        ],
        'jobs' => [
            'id', 'queue', 'payload', 'attempts',
            'reserved_at', 'available_at', 'created_at',
        ],
        'job_batches' => [
            'id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs',
            'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at',
        ],
        'failed_jobs' => [
            'id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at',
        ],
    ];

    /**
     * Application + dependency readiness probe.
     *
     * The separate Laravel /up route is liveness only and never connects to
     * PostgreSQL. This route is suitable for readiness checks at the ingress.
     */
    public function readiness(): JsonResponse
    {
        $connectionName = (string) config('database.default', 'unknown');

        try {
            DB::connection($connectionName)->selectOne('SELECT 1');

            $missingSchema = [];
            foreach (self::REQUIRED_COLUMNS as $table => $columns) {
                if (!Schema::connection($connectionName)->hasTable($table)) {
                    $missingSchema[] = $table;
                    continue;
                }

                $actualColumns = Schema::connection($connectionName)->getColumnListing($table);
                foreach ($columns as $column) {
                    if (!in_array($column, $actualColumns, true)) {
                        $missingSchema[] = $table . '.' . $column;
                    }
                }
            }

            if ($missingSchema !== []) {
                return response()->json([
                    'success' => false,
                    'status' => 'not_ready',
                    'checks' => [
                        'application' => 'ok',
                        'database' => 'ok',
                        'schema' => 'incomplete',
                    ],
                ], 503);
            }

            return response()->json([
                'success' => true,
                'status' => 'ready',
                'checks' => [
                    'application' => 'ok',
                    'database' => 'ok',
                    'schema' => 'ready',
                ],
            ]);
        } catch (Throwable $exception) {
            // Use PHP's native error log here: readiness must still return
            // HTTP 503 even when the Laravel logging channel itself is unavailable.
            // Never include SQL, exception messages, hosts, usernames, or passwords.
            $logRecord = [
                'event' => 'raven23_readiness_check_failed',
                'connection' => $connectionName,
                'exception_type' => get_class($exception),
            ];
            error_log(
                json_encode($logRecord, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                    ?: 'raven23_readiness_check_failed',
            );

            return response()->json([
                'success' => false,
                'status' => 'not_ready',
                'checks' => [
                    'application' => 'ok',
                    'database' => 'unavailable',
                    'schema' => 'unknown',
                ],
                'source_commit' => $this->sourceCommit(),
            ], 503);
        }
    }

    /**
     * API health endpoint keeps the existing high-level response fields,
     * while removing Render/Neon-specific configuration and identity output.
     */
    public function apiHealth(): JsonResponse
    {
        $response = $this->readiness();
        $payload = $response->getData(true);
        $checks = $payload['checks'] ?? [];

        return response()->json([
            'success' => ($payload['success'] ?? false) === true,
            'service' => 'raven23-api',
            'version' => '1',
            'database' => ($checks['database'] ?? null) === 'ok'
                ? 'connected'
                : 'unavailable',
            'schema' => ($checks['schema'] ?? null) === 'ready'
                ? 'ready'
                : (($checks['schema'] ?? null) === 'incomplete' ? 'incomplete' : 'unknown'),
        ], $response->getStatusCode());
    }

}
