<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'The given data was invalid.',
                    'errors' => $exception->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // Never expose SQL, database paths, parameter values or stack details to API clients.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return null;
            }

            $requestId = (string) Str::uuid();
            $connection = (string) config('database.default');
            $context = [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'exception_type' => get_class($exception),
                'source_file' => basename($exception->getFile()),
                'source_line' => $exception->getLine(),
                'db_connection' => $connection,
                'db_driver' => config("database.connections.{$connection}.driver"),
            ];

            // Log only diagnostic metadata. Do not log the exception message,
            // SQL statement, bindings, password, email, or request body.
            if ($exception instanceof QueryException) {
                $context['sql_state'] = $exception->errorInfo[0] ?? null;
                $context['database_error_code'] = $exception->errorInfo[1] ?? null;
            }

            Log::error('RAVEN23 API request failed', $context);
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            return response()->json([
                'success' => false,
                'message' => 'The server could not complete the request. Please try again. Reference: ' . $requestId,
                'request_id' => $requestId,
            ], $status >= 500 ? $status : 500);
        });
    })->create();
