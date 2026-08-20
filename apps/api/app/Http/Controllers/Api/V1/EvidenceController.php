<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Claims\Models\Claim;
use App\Domain\Evidence\Models\Evidence;
use App\Http\Controllers\Controller;
use App\Http\Resources\EvidenceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EvidenceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Claim::class);

        $query = Evidence::query()
            ->when($request->filled('claim_id'), fn ($q) => $q->where('claim_id', $request->string('claim_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('live_only'), fn ($q) => $q->where('source', 'CAMARA'))
            ->latest('received_at');

        return EvidenceResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function forClaim(Claim $claim): AnonymousResourceCollection
    {
        $this->authorize('viewEvidence', $claim);

        return EvidenceResource::collection(
            $claim->evidence()->orderBy('received_at')->get()
        );
    }

    public function show(Evidence $evidence): EvidenceResource
    {
        $this->authorize('viewEvidence', $evidence->claim);

        return new EvidenceResource($evidence);
    }
}
