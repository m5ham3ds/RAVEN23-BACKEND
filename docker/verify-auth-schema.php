<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;

$root = getcwd();
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$requiredColumns = [
    'users' => [
        'id', 'name', 'email', 'password', 'username', 'profile_id',
        'provider', 'google_id', 'is_verified', 'is_account_verified',
        'is_guest', 'created_at', 'updated_at',
    ],
    'personal_access_tokens' => [
        'id', 'tokenable_type', 'tokenable_id', 'name', 'token',
        'abilities', 'last_used_at', 'expires_at',
        'device_id', 'device_name', 'platform',
        'created_at', 'updated_at',
    ],
];

try {
    $missing = [];

    foreach ($requiredColumns as $table => $columns) {
        if (!Schema::hasTable($table)) {
            $missing[] = $table;
            continue;
        }

        $actual = Schema::getColumnListing($table);
        foreach ($columns as $column) {
            if (!in_array($column, $actual, true)) {
                $missing[] = $table . '.' . $column;
            }
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Runtime DB_URL is missing required RAVEN23 schema: ' . implode(', ', $missing)
        );
    }

    // Exercise the exact production persistence path. Keep each write in a
    // savepoint so PostgreSQL can report the first failing statement without
    // poisoning the outer transaction. All rows are rolled back afterward.
    DB::beginTransaction();
    try {
        $probe = User::create([
            'name' => 'RAVEN23 Schema Probe',
            'email' => 'raven23-probe-' . Str::lower(Str::random(20)) . '@invalid.raven23',
            'username' => 'raven_probe_' . Str::lower(Str::random(12)),
            'password' => Str::random(48),
            'provider' => 'email',
            'is_guest' => false,
        ]);

        DB::beginTransaction();
        try {
            $issued = $probe->createToken('raven23-schema-probe');
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            throw new RuntimeException(
                'Sanctum token insert failed: ' . get_class($exception)
                . '; SQLSTATE=' . (string) ($exception instanceof \Illuminate\Database\QueryException
                    ? ($exception->errorInfo[0] ?? 'unknown')
                    : 'non-sql')
                . '; driver_code=' . (string) ($exception instanceof \Illuminate\Database\QueryException
                    ? ($exception->errorInfo[1] ?? 'unknown')
                    : 'n/a')
                . '; detail=' . preg_replace('/[\\r\\n]+/', ' ', $exception->getMessage()),
                0,
                $exception
            );
        }

        DB::beginTransaction();
        try {
            $issued->accessToken->forceFill([
                'device_id' => null,
                'device_name' => 'Schema verification',
                'platform' => 'android',
            ])->save();
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            throw new RuntimeException(
                'Sanctum device metadata write failed: ' . get_class($exception)
                . '; SQLSTATE=' . (string) ($exception instanceof \Illuminate\Database\QueryException
                    ? ($exception->errorInfo[0] ?? 'unknown')
                    : 'non-sql')
                . '; driver_code=' . (string) ($exception instanceof \Illuminate\Database\QueryException
                    ? ($exception->errorInfo[1] ?? 'unknown')
                    : 'n/a')
                . '; detail=' . preg_replace('/[\\r\\n]+/', ' ', $exception->getMessage()),
                0,
                $exception
            );
        }
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    fwrite(STDOUT, "Verified: account, Sanctum token and device metadata writes succeeded; probe rows rolled back.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Runtime DB_URL schema validation failed: "
        . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
