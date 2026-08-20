<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePolicyRequest;
use App\Http\Resources\PolicyResource;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PolicyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VerificationPolicy::class);

        return PolicyResource::collection(VerificationPolicy::orderBy('key')->get());
    }

    public function show(VerificationPolicy $policy): PolicyResource
    {
        $this->authorize('view', $policy);

        return new PolicyResource($policy);
    }

    public function update(UpdatePolicyRequest $request, VerificationPolicy $policy): PolicyResource
    {
        $this->authorize('update', $policy);

        $before = $policy->only(['required_evidence', 'max_tool_calls', 'allow_partial', 'assurance_level']);

        $policy->fill($request->validated())->save();

        $this->audit->log(AuditEventType::POLICY_UPDATED, $policy, [
            'before' => $before,
            'after' => $policy->only(['required_evidence', 'max_tool_calls', 'allow_partial', 'assurance_level']),
        ]);

        return new PolicyResource($policy);
    }
}
