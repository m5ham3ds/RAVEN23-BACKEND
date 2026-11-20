<?php

use Illuminate\\Contracts\\Console\\Kernel;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;
use App\\Models\\User;
use Illuminate\\Database\\QueryException;

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

/**
 * Return safe database error metadata only. Never include SQL, bound values,
 * hostnames, usernames, passwords, or connection URIs in startup logs.
 */
function raven23DbFailure(string $stage, Throwable $exception): RuntimeException
{
    $errorInfo = $exception instanceof QueryException
        ? ($exception->errorInfo ?? [])
        : ($exception instanceof PDOException ? ($exception->errorInfo ?? []) : []);

    $sqlState = is_array($errorInfo) ? (string) ($errorInfo[0] ?? 'unknown') : 'unknown';
    $driverCode = is_array($errorInfo) ? (string) ($errorInfo[1] ?? 'unknown') : 'unknown';

    return new RuntimeException(
        'stage=' . $stage
        . '; exception=' . get_class($exception)
        . '; sqlstate=' . $sqlState
        . '; driver_code=' . $driverCode
        . '; transaction_level=' . (string) DB::connection()->transactionLevel(),
        0,
        $exception
    );
}

function raven23RunIsolated(string $stage, Closure $operation): mixed
{
    $connection = DB::connection();
    if ($connection->transactionLevel() !== 0) {
        throw new RuntimeException('Cannot run isolated startup probe with an open transaction.');
    }

    try {
        return $operation();
    } catch (Throwable $exception) {
        // The operation owns its transaction. Roll it back before propagating
        // a sanitized diagnostic so the original PostgreSQL failure is kept.
        while ($connection->transactionLevel() > 0) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                break;
            }
        }
        throw raven23DbFailure($stage, $exception);
    }
}

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

    $email = 'raven23-probe-' . Str::lower(Str::random(20)) . '@invalid.raven23';
    $username = 'raven_probe_' . Str::lower(Str::random(12));

    // No enclosing transaction: PostgreSQL's transaction-aborted state must
    // never hide the first failing write. Probe records are removed explicitly.
    $probe = raven23RunIsolated('account_insert', static function () use ($email, $username): User {
        return User::create([
            'name' => 'RAVEN23 Schema Probe',
            'email' => $email,
            'username' => $username,
            'password' => Str::random(48),
            'provider' => 'email',
            'is_guest' => false,
        ]);
    });

    $issued = null;
    try {
        $issued = raven23RunIsolated('sanctum_token_insert', static function () use ($probe) {
            return $probe->createToken('raven23-schema-probe');
        });

        raven23RunIsolated('sanctum_device_metadata_update', static function () use ($issued): void {
            $issued->accessToken->forceFill([
                'device_id' => null,
                'device_name' => 'Schema verification',
                'platform' => 'android',
            ])->save();
        });
    } finally {
        // Clean only the uniquely-generated probe rows; never touch real users
        // or existing tokens. Each cleanup is isolated from the write probes.
        if ($issued !== null) {
            raven23RunIsolated('sanctum_probe_cleanup', static function () use ($issued): void {
                $issued->accessToken->delete();
            });
        }
        raven23RunIsolated('account_probe_cleanup', static function () use ($probe): void {
            $probe->delete();
        });
    }

    fwrite(STDOUT, "Verified: account, Sanctum token and device metadata writes succeeded; probe rows cleaned up.\\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Runtime DB_URL schema validation failed: "
        . get_class($exception) . ': ' . $exception->getMessage() . "\\n");
    exit(1);
}
