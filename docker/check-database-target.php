<?php

/**
 * Ensure migrations and web requests target the same Neon database.
 * Neon pooled hostnames differ from direct hostnames by "-pooler".
 */

function raven23Target(string $variable): array
{
    $raw = trim((string) getenv($variable));
    if ($raw === '') {
        throw new RuntimeException($variable . ' is required.');
    }

    $parts = parse_url($raw);
    if (!is_array($parts)) {
        throw new RuntimeException($variable . ' must be a PostgreSQL connection URI.');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['postgres', 'postgresql'], true)) {
        throw new RuntimeException($variable . ' must use a postgres:// or postgresql:// URI.');
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    $host = (string) preg_replace('/-pooler(?=\.|$)/', '', $host);
    $database = rawurldecode(trim((string) ($parts['path'] ?? ''), '/'));
    $port = (string) ($parts['port'] ?? 5432);
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);

    $schema = strtolower(trim((string) ($query['schema'] ?? 'public')));
    $options = (string) ($query['options'] ?? '');
    if (preg_match('/search_path\s*=\s*"?([a-zA-Z0-9_,]+)/i', $options, $match) === 1) {
        $schema = strtolower($match[1]);
    }

    if ($host === '' || $database === '') {
        throw new RuntimeException($variable . ' is missing its PostgreSQL host or database name.');
    }

    return [
        'host' => $host,
        'database' => $database,
        'port' => $port,
        'schema' => $schema,
    ];
}

try {
    $runtime = raven23Target('DB_URL');
    $migrations = raven23Target('DB_MIGRATION_URL');

    if ($runtime !== $migrations) {
        $different = [];
        foreach (array_keys($runtime) as $key) {
            if ($runtime[$key] !== $migrations[$key]) {
                $different[] = $key;
            }
        }

        throw new RuntimeException(
            'DB_URL and DB_MIGRATION_URL do not target the same Neon PostgreSQL endpoint/database/schema. '
            . 'Mismatch: ' . implode(', ', $different)
            . '. Use pooled and direct connection strings from the same Neon project, branch and database.'
        );
    }

    fwrite(STDOUT, "Verified: DB_URL and DB_MIGRATION_URL point to the same PostgreSQL target.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Database target validation failed: " . $exception->getMessage() . "\n");
    exit(1);
}
