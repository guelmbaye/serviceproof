<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Claims\Models\Claim;
use App\Domain\Shared\Enums\Role;
use App\Domain\WorkOrders\Models\WorkOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClaimRequest;
use App\Http\Requests\VerifyClaimRequest;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\VerificationRunResource;
use App\Services\Claims\ClaimService;
use App\Services\Verification\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClaimController extends Controller
{
    public function __construct(
        private readonly ClaimService $claims,
        private readonly VerificationService $verification,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Claim::class);

        $query = Claim::query()
            ->with(['workOrder', 'user', 'currentDecision'])
            ->when(
                $request->user()->role === Role::FIELD_WORKER,
                fn ($q) => $q->where('user_id', $request->user()->id)
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('decision'), fn ($q) => $q->whereHas(
                'currentDecision',
                fn ($sub) => $sub->where('state', $request->string('decision'))
            ))
            ->when($request->filled('work_order_id'), fn ($q) => $q->where('work_order_id', $request->string('work_order_id')))
            ->latest();

        return ClaimResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Claim $claim): ClaimResource
    {
        $this->authorize('view', $claim);

        $claim->load(['workOrder', 'user', 'device', 'currentDecision']);

        // Raw network evidence is back-office only. A field worker sees the
        // outcome of their claim, not the telecom signals behind it.
        if (auth()->user()->can('viewEvidence', $claim)) {
            $claim->load(['latestRun.evidence', 'latestRun.traceEvents', 'latestRun.decision', 'latestRun.policy']);
        } else {
            $claim->load('latestRun');
        }

        return new ClaimResource($claim);
    }

    /** Field worker submits a completed intervention. */
    public function store(StoreClaimRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('submitClaim', $workOrder);

        $claim = $this->claims->submit($workOrder, $request->user(), $request->validated());

        return (new ClaimResource($claim->load(['workOrder', 'user', 'device'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Run the assurance loop:
     * claim -> agent -> CAMARA evidence -> policy -> decision.
     */
    public function verify(VerifyClaimRequest $request, Claim $claim): JsonResponse
    {
        $this->authorize('verify', $claim);

        $run = $this->verification->verify($claim, $request->user(), $request->validated());

        return response()->json([
            'data' => new VerificationRunResource(
                $run->load(['evidence', 'traceEvents', 'decision', 'policy'])
            ),
            'claim' => new ClaimResource($claim->fresh(['workOrder', 'currentDecision'])),
        ]);
    }
}
