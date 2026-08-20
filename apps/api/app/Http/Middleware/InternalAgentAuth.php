<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal channel used by the FastAPI agent runtime to stream
 * progress back into Laravel. This is never a public endpoint and is not
 * reachable from the browser or the mobile app.
 */
class InternalAgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.agent.token');
        $provided = (string) $request->header('X-Internal-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Invalid internal token.'],
            ], 403);
        }

        return $next($request);
    }
}
