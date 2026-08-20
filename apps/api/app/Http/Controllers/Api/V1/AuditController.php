<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->role->canReview(), 403);

        $query = AuditEvent::query()
            ->with('actor')
            ->when($this->tenant->hasOrganization(), fn ($q) => $q->where('organization_id', $this->tenant->organizationId()))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->string('event_type')))
            ->when($request->filled('resource_id'), fn ($q) => $q->where('resource_id', $request->string('resource_id')))
            ->when($request->filled('actor_type'), fn ($q) => $q->where('actor_type', $request->string('actor_type')))
            ->orderByDesc('occurred_at');

        return AuditEventResource::collection($query->paginate($request->integer('per_page', 50)));
    }
}
