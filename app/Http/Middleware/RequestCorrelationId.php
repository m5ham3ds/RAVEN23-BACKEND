<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Always generate an ID at the edge. Do not trust an arbitrary
        // caller-supplied value as the canonical correlation identifier.
        $requestId = (string) Str::uuid();
        $request->attributes->set('raven23_request_id', $requestId);

        Log::withContext(['request_id' => $requestId]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);

            if ($request->is('api/*') || $request->is('ready')) {
                $response->headers->set('Cache-Control', 'no-store');
            }

            return $response;
        } finally {
            // FrankenPHP may serve multiple requests in one long-lived
            // process. Never let one request's context bleed into another.
            Log::withoutContext();
        }
    }
}
