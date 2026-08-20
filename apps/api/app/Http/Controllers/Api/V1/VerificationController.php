<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Verification\Models\VerificationRun;
use App\Http\Controllers\Controller;
use App\Http\Resources\TraceEventResource;
use App\Http\Resources\VerificationRunResource;
use App\Services\Agent\AgentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VerificationController extends Controller
{
    public function __construct(private readonly AgentGateway $agent) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VerificationRun::class);

        $query = VerificationRun::query()
            ->with(['policy', 'decision', 'claim.workOrder'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('escalated_only'), fn ($q) => $q->where('escalated', true))
            ->latest();

        return VerificationRunResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(VerificationRun $verification): VerificationRunResource
    {
        $this->authorize('view', $verification);

        return new VerificationRunResource(
            $verification->load(['evidence', 'traceEvents', 'decision', 'policy'])
        );
    }

    /** The agent trace, for the Verification Center timeline. */
    public function trace(VerificationRun $verification): AnonymousResourceCollection
    {
        $this->authorize('view', $verification);

        return TraceEventResource::collection($verification->traceEvents()->get());
    }

    /** Health of the network + AI dependencies, for the ops dashboard. */
    public function health(): JsonResponse
    {
        return response()->json([
            'agent' => $this->agent->health(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
