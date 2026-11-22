<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
), static fn (string $origin): bool => $origin !== ''));

foreach ($allowedOrigins as $origin) {
    // Fruitcake converts any asterisk in allowedOrigins into a wildcard
    // pattern, so reject embedded wildcards as well as a standalone "*".
    if (str_contains($origin, '*')) {
        throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS must list exact origins; wildcard origins are not allowed.');
    }

    $parts = parse_url($origin);
    if (
        ! is_array($parts)
        || ! isset($parts['scheme'], $parts['host'])
        || ! in_array($parts['scheme'], ['http', 'https'], true)
        || isset($parts['path'])
        || isset($parts['query'])
        || isset($parts['fragment'])
    ) {
        throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS contains an invalid origin.');
    }
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // Empty by default: native Android clients do not require browser CORS.
    // Browser origins must be explicitly configured, never wildcarded.
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-Request-ID',
    ],
    'exposed_headers' => ['X-Request-ID', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
