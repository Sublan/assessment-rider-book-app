<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a correlation id to every request, echoes it back, and puts it in
 * every log line written while handling the request. An incident report can
 * then be traced from the client's X-Request-Id straight into the logs.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('x-request-id') ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['requestId' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
