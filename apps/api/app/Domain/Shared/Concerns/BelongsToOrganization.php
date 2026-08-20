<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Organizations\Models\Organization;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant isolation is enforced in the data layer, not in the UI.
 * A valid login never grants access to another organisation's records.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->hasOrganization()) {
                $builder->where($builder->getModel()->getTable().'.organization_id', $context->organizationId());
            }
        });

        static::creating(function ($model) {
            if (! $model->organization_id) {
                $model->organization_id = app(TenantContext::class)->organizationId();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Escape hatch for system-level work. Use sparingly and audit it. */
    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }
}
