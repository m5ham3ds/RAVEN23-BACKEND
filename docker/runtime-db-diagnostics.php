<?php

declare(strict_types=1);

/**
 * RAVEN23 runtime database diagnostic.
 * Never prints connection URLs, usernames, passwords, or query parameters.
 */

$mode = getenv('RAVEN_DIAG_MODE') ?: 'unspecified';
$url = trim((string) getenv('DB_URL'));

function out(string $key, string $value): void
{
    echo $key . '=' . $value . PHP_EOL;
}

function safeTarget(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new RuntimeException('DB_URL is not a valid URL.');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['postgres', 'postgresql'], true)) {
        throw new RuntimeException('DB_URL scheme is not postgres/postgresql.');
    }

    $host = (string) ($parts['host'] ?? '');
    $database = rawurldecode(trim((string) ($parts['path'] ?? ''), '/'));
    $port = (int) ($parts['port'] ?? 5432);

    if ($host === '' || $database === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('DB_URL is missing host/database or has an invalid port.');
    }

    return [$host, $database, $port];
}

out('MODE', $mode);
out('DB_URL_PRESENT', $url !== '' ? 'yes' : 'no');
out('DB_CONNECTION_ENV', (string) (getenv('DB_CONNECTION') ?: 'not-set'));
out('APP_ENV', (string) (getenv('APP_ENV') ?: 'not-set'));

if ($url === '') {
    out('RESULT', 'FAIL: DB_URL missing');
    exit(2);
}

try {
    [$host, $database, $port] = safeTarget($url);
    out('URL_SCHEME', 'postgresql');
    out('URL_HOST', $host);
    out('URL_PORT', (string) $port);
    out('URL_DATABASE', $database);
    out('URL_USERNAME_PRESENT', (string) (parse_url($url, PHP_URL_USER) !== null ? 'yes' : 'no'));
    out('URL_PASSWORD_PRESENT', (string) (parse_url($url, PHP_URL_PASS) !== null ? 'yes' : 'no'));

    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    $ips = [];
    foreach (($records ?: []) as $record) {
        if (isset($record['ip'])) $ips[] = $record['ip'];
        if (isset($record['ipv6'])) $ips[] = $record['ipv6'];
    }
    out('DNS', $ips ? implode(',', array_unique($ips)) : 'FAILED');

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        12,
        STREAM_CLIENT_CONNECT
    );
    if ($socket === false) {
        out('TCP', 'FAILED (' . $errno . ')');
    } else {
        out('TCP', 'SUCCESS');
        fclose($socket);
    }
} catch (Throwable $e) {
    out('PRECHECK', 'FAILED: ' . get_class($e));
    out('RESULT', 'FAIL');
    exit(3);
}

try {
    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $connection = config('database.default');
    $pg = config('database.connections.pgsql', []);
    out('LARAVEL_DEFAULT_CONNECTION', (string) $connection);
    out('LARAVEL_PGSQL_DRIVER', (string) ($pg['driver'] ?? 'missing'));
    out('LARAVEL_PGSQL_URL_PRESENT', !empty($pg['url']) ? 'yes' : 'no');

    Illuminate\Support\Facades\DB::purge('pgsql');
    $row = Illuminate\Support\Facades\DB::connection('pgsql')->selectOne(
        'SELECT current_database() AS database_name, current_user AS database_user'
    );

    out('PDO_QUERY', 'SUCCESS');
    out('CONNECTED_DATABASE', (string) $row->database_name);
    out('CONNECTED_USER', (string) $row->database_user);

    // Optional read-only inspection of specific synthetic account IDs from
    // earlier production smoke attempts. Never select or print email, name,
    // password hash, token hash, or any bearer credential.
    $inspectRaw = trim((string) (getenv('RAVEN_DIAG_INSPECT_USER_IDS') ?: ''));
    if ($inspectRaw !== '') {
        $requestedIds = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $inspectRaw)),
            static fn (int $id): bool => $id > 0
        )));
        if (count($requestedIds) > 20) {
            throw new RuntimeException('Too many user IDs requested for read-only diagnostics.');
        }

        foreach ($requestedIds as $userId) {
            $inspection = Illuminate\Support\Facades\DB::connection('pgsql')->selectOne(
                'SELECT
                    EXISTS(SELECT 1 FROM users WHERE id = ?) AS user_exists,
                    (SELECT COUNT(*) FROM personal_access_tokens WHERE tokenable_type = ? AND tokenable_id = ?) AS user_token_rows,
                    (SELECT is_guest FROM users WHERE id = ?) AS is_guest,
                    (SELECT provider FROM users WHERE id = ?) AS provider',
                [
                    $userId,
                    App\Models\User::class,
                    $userId,
                    $userId,
                    $userId,
                ]
            );

            $userExists = in_array($inspection->user_exists, [true, 1, '1', 't', 'true'], true);
            $rawGuest = $inspection->is_guest;
            $guestValue = $rawGuest === null
                ? 'null'
                : (in_array($rawGuest, [true, 1, '1', 't', 'true'], true) ? 'true' : 'false');
            $provider = $inspection->provider === null
                ? 'absent'
                : preg_replace('/[^a-z0-9_-]/i', '', (string) $inspection->provider);

            out(
                'USER_ID_' . $userId,
                'exists=' . ($userExists ? 'yes' : 'no')
                . ';token_rows=' . (string) $inspection->user_token_rows
                . ';is_guest=' . $guestValue
                . ';provider=' . $provider
            );
        }
    }

    out('RESULT', 'PASS');
} catch (Throwable $e) {
    out('PDO_QUERY', 'FAILED');
    out('EXCEPTION_TYPE', get_class($e));

    $state = 'unknown';
    $code = 'unknown';
    $previous = $e;
    while ($previous) {
        if ($previous instanceof PDOException) {
            $info = $previous->errorInfo ?? [];
            $state = (string) ($info[0] ?? ($previous->getCode() ?: 'unknown'));
            $code = (string) ($info[1] ?? 'unknown');
            break;
        }
        $previous = $previous->getPrevious();
    }

    out('SQLSTATE', $state);
    out('DATABASE_ERROR_CODE', $code);
    out('RESULT', 'FAIL');
    exit(4);
}
