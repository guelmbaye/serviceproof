<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant scope is derived from the authenticated user, never from a client
 * header. A SUPER_ADMIN may explicitly target one organisation, or work
 * platform-wide; both are recorded in the request context.
 */
class EnsureOrganizationContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['error' => ['code' => 'ACCOUNT_SUSPENDED', 'message' => 'This account is not active.']], 403);
        }

        if ($user->isSuperAdmin()) {
            $target = $request->header('X-Organization-Id');

            if ($target) {
                $this->context->set($target);
            } else {
                $this->context->allowPlatformWide();
            }

            return $next($request);
        }

        if (! $user->organization_id) {
            return response()->json(['error' => ['code' => 'NO_ORGANIZATION', 'message' => 'This account is not attached to an organization.']], 403);
        }

        if (! $user->organization?->isActive()) {
            return response()->json(['error' => ['code' => 'ORGANIZATION_SUSPENDED', 'message' => 'This organization is suspended.']], 403);
        }

        $this->context->set($user->organization_id);

        return $next($request);
    }
}
