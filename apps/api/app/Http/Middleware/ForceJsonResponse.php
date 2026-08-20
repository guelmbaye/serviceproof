<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    /**
     * Every API request carries a correlation id. It flows into the audit
     * log, the agent runtime and the CAMARA adapter, so a single decision
     * can be traced end to end.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
