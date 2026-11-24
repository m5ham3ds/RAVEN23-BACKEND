<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';

$token = (string) getenv('RAVEN23_SYNC_TOKEN');
$encodedPayload = (string) getenv('RAVEN23_SYNC_PAYLOAD');
$readyFile = (string) getenv('RAVEN23_SYNC_READY_FILE');
$barrierFile = (string) getenv('RAVEN23_SYNC_BARRIER_FILE');
$payloadJson = base64_decode($encodedPayload, true);
if ($token === '' || $payloadJson === false || $readyFile === '' || $barrierFile === '') {
    fwrite(STDERR, "Missing parallel sync worker input.\n");
    exit(2);
}
$payload = json_decode($payloadJson, true, 64, JSON_THROW_ON_ERROR);

$app = require $basePath.'/bootstrap/app.php';
$kernel = $app->make(HttpKernel::class);
$app->make('db')->connection('pgsql')->selectOne('select 1');
file_put_contents($readyFile, 'ready');

$deadline = microtime(true) + 20;
while (! is_file($barrierFile) && microtime(true) < $deadline) {
    usleep(1_000);
}
if (! is_file($barrierFile)) {
    fwrite(STDERR, "Parallel sync start barrier timed out.\n");
    exit(3);
}

$request = Request::create(
    '/api/v1/sync/wellness',
    'POST',
    [],
    [],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
    ],
    json_encode($payload, JSON_THROW_ON_ERROR),
);
$response = $kernel->handle($request);
$body = json_decode((string) $response->getContent(), true);
$kernel->terminate($request, $response);

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'success' => is_array($body) && ($body['success'] ?? false) === true,
], JSON_THROW_ON_ERROR));
exit($response->isSuccessful() ? 0 : 1);
