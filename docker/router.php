<?php

declare(strict_types=1);

/**
 * Production router for PHP's built-in HTTP server.
 *
 * Unlike "php artisan serve", this runs in the same process environment
 * and does not create Laravel's development-server child process with its
 * restricted environment-variable passthrough list.
 */

$documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? (getcwd() . '/public'));
$publicRoot = realpath($documentRoot);
if ($publicRoot === false) {
    http_response_code(500);
    echo 'RAVEN23 public directory is unavailable.';
    return true;
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';

// Never let a traversal path escape the public directory.
if (str_contains($requestPath, "\0") || preg_match('~(^|/)\.\.(/|$)~', str_replace('\\', '/', $requestPath))) {
    require $publicRoot . '/index.php';
    return true;
}

$relativePath = ltrim($requestPath, '/');
$requestedFile = $publicRoot . ($relativePath === '' ? '' : DIRECTORY_SEPARATOR . $relativePath);

// Let PHP serve existing public assets directly (including the public/storage
// symlink); all application routes go through Laravel's front controller.
if ($requestPath !== '/' && is_file($requestedFile)) {
    return false;
}

require $publicRoot . '/index.php';
return true;
