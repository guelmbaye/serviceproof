<?php

namespace App\Support;

/**
 * Request-scoped tenant context.
 *
 * Every tenant-scoped query is filtered through this object. It is set once,
 * by middleware, from the authenticated user — never from a client-supplied
 * header or body field. A SUPER_ADMIN may explicitly widen the scope, and
 * that widening is itself audited.
 */
class TenantContext
{
    private ?string $organizationId = null;

    private bool $unscoped = false;

    public function set(?string $organizationId): void
    {
        $this->organizationId = $organizationId;
        $this->unscoped = false;
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    public function hasOrganization(): bool
    {
        return $this->organizationId !== null && ! $this->unscoped;
    }

    /** Platform-wide access. Only ever granted to SUPER_ADMIN. */
    public function allowPlatformWide(): void
    {
        $this->unscoped = true;
        $this->organizationId = null;
    }

    public function isPlatformWide(): bool
    {
        return $this->unscoped;
    }

    /** Run a closure without tenant scoping (internal/system work only). */
    public function withoutScope(callable $callback): mixed
    {
        $previousId = $this->organizationId;
        $previousUnscoped = $this->unscoped;

        $this->allowPlatformWide();

        try {
            return $callback();
        } finally {
            $this->organizationId = $previousId;
            $this->unscoped = $previousUnscoped;
        }
    }
}
