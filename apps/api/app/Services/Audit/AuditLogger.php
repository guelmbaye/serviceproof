<?php

namespace App\Services\Audit;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AuditEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function log(
        AuditEventType $type,
        ?Model $resource = null,
        array $metadata = [],
        ?User $actor = null,
        string $actorType = 'USER',
        ?string $organizationId = null,
    ): AuditEvent {
        $actor ??= auth()->user();

        return AuditEvent::create([
            'organization_id' => $organizationId
                ?? $resource?->organization_id
                ?? $actor?->organization_id,
            'actor_id' => $actor?->id,
            'actor_type' => $actorType,
            'event_type' => $type->value,
            'resource_type' => $resource ? class_basename($resource) : null,
            'resource_id' => $resource?->getKey(),
            'metadata' => $metadata ?: null,
            'request_id' => Request::instance()->attributes->get('request_id'),
            'ip_address' => Request::ip(),
            'occurred_at' => now(),
        ]);
    }

    /** Events attributed to the agent runtime rather than a human. */
    public function logAgent(AuditEventType $type, ?Model $resource = null, array $metadata = [], ?string $organizationId = null): AuditEvent
    {
        return $this->log($type, $resource, $metadata, null, 'AGENT', $organizationId);
    }

    public function logSystem(AuditEventType $type, ?Model $resource = null, array $metadata = [], ?string $organizationId = null): AuditEvent
    {
        return $this->log($type, $resource, $metadata, null, 'SYSTEM', $organizationId);
    }
}
