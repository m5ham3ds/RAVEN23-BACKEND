<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;

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

            report($exception);
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            return response()->json([
                'success' => false,
                'message' => 'The server could not complete the request. Please try again.',
            ], $status >= 500 ? $status : 500);
        });
    })->create();
