<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

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

    fwrite(STDOUT, "Verified: runtime database schema is ready for registration, Sanctum sessions and device identity.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Runtime DB_URL schema validation failed: "
        . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
