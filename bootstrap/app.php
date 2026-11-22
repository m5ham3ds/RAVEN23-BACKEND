<?php

use App\Http\Middleware\RequestCorrelationId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust only explicitly listed proxy IPs/CIDRs. A wildcard would let
        // untrusted clients spoof X-Forwarded-* headers and is rejected.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trustedProxies !== '') {
            $proxies = array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
            foreach ($proxies as $proxy) {
                if ($proxy === '*') {
                    throw new RuntimeException('TRUSTED_PROXIES must contain exact IP addresses or CIDRs, not a wildcard.');
                }

                [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
                if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                    throw new RuntimeException('TRUSTED_PROXIES contains an invalid IP address.');
                }

                if ($prefix !== null) {
                    $maxPrefix = str_contains($address, ':') ? 128 : 32;
                    if (! ctype_digit($prefix) || (int) $prefix > $maxPrefix) {
                        throw new RuntimeException('TRUSTED_PROXIES contains an invalid CIDR prefix.');
                    }
                }
            }

            $middleware->trustProxies(at: $proxies);
        }

        $middleware->append(RequestCorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API exceptions are reported only by the redacted renderer below.
        // Suppress Laravel's default reporter because it may log the exception
        // message and request context, including sensitive user input.
        $exceptions->dontReportWhen(static fn (Throwable $exception): bool => request()->is('api/*'));

        // QueryException is also suppressed outside API routes to avoid SQL/bindings.
        $exceptions->dontReport([QueryException::class]);

        $requestId = static function (Request $request): string {
            return (string) (
                $request->attributes->get('raven23_request_id')
                ?: Str::uuid()
            );
        };

        $withCorrelationHeaders = static function ($response, string $id) {
            $response->headers->set('X-Request-ID', $id);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        };

        $exceptions->render(function (ValidationException $exception, Request $request) use ($requestId, $withCorrelationHeaders) {
            if ($request->is('api/*')) {
                $id = $requestId($request);

                return $withCorrelationHeaders(response()->json([
                    'success' => false,
                    'message' => 'The given data was invalid.',
                    'errors' => $exception->errors(),
                    'request_id' => $id,
                ], 422), $id);
            }
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($requestId, $withCorrelationHeaders) {
            if ($request->is('api/*')) {
                $id = $requestId($request);

                return $withCorrelationHeaders(response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'request_id' => $id,
                ], 401), $id);
            }
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($requestId, $withCorrelationHeaders) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                return null;
            }

            $id = $requestId($request);

            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                $status = $exception->getStatusCode();
                $message = $status === 429
                    ? 'Too many requests. Please try again later.'
                    : 'The request could not be completed.';

                $response = response()->json([
                    'success' => false,
                    'message' => $message,
                    'request_id' => $id,
                ], $status);

                foreach ($exception->getHeaders() as $name => $value) {
                    if (in_array(strtolower($name), ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining'], true)) {
                        $response->headers->set($name, $value);
                    }
                }

                return $withCorrelationHeaders($response, $id);
            }

            $route = $request->route();
            $diagnostic = [
                'exception_type' => get_class($exception),
                'source_file' => basename($exception->getFile()),
                'source_line' => $exception->getLine(),
                'auth_stage' => $request->attributes->get('raven23_auth_stage'),
            ];

            // Record the first application frame, not a vendor stack trace.
            $applicationCallSite = null;
            foreach ($exception->getTrace() as $frame) {
                $file = (string) ($frame['file'] ?? '');
                if ($file === '' || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $applicationCallSite = [
                    'file' => basename($file),
                    'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                    'class' => isset($frame['class']) ? (string) $frame['class'] : null,
                    'function' => isset($frame['function']) ? (string) $frame['function'] : null,
                ];
                break;
            }

            $diagnostic['application_call_site'] = $applicationCallSite;
            if ($exception instanceof QueryException) {
                $diagnostic['sql_state'] = $exception->errorInfo[0] ?? null;
            }

            // Never log request bodies, email addresses, tokens, exception
            // messages, SQL, bindings, DB hostnames, or database/schema names.
            Log::error('RAVEN23 API request failed', [
                'request_id' => $id,
                'method' => $request->method(),
                'route' => is_object($route) && method_exists($route, 'uri') ? $route->uri() : 'unmatched',
                'db_driver' => config('database.connections.'.config('database.default').'.driver'),
                ...$diagnostic,
            ]);

            return $withCorrelationHeaders(response()->json([
                'success' => false,
                'message' => 'The server could not complete the request. Please try again.',
                'request_id' => $id,
            ], 500), $id);
        });
    })->create();
