<?php

namespace App\Http\Controllers\Internal;

use App\Domain\Verification\Models\VerificationRun;
use App\Http\Controllers\Controller;
use App\Services\Verification\TraceRecorder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optional progress channel: the agent runtime can stream trace events while
 * a verification is still running, so the Verification Center animates live
 * instead of jumping straight to the result.
 *
 * Guarded by the internal token; never reachable from a browser or the app.
 */
class AgentEventController extends Controller
{
    public function __construct(
        private readonly TraceRecorder $traceRecorder,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_run_id' => ['required', 'uuid'],
            'events' => ['required', 'array', 'min:1'],
            'events.*.event_type' => ['required', 'string'],
            'events.*.label' => ['required', 'string', 'max:255'],
            'events.*.detail' => ['nullable', 'array'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ]);

        $written = $this->tenant->withoutScope(function () use ($data) {
            $run = VerificationRun::withoutGlobalScope('organization')
                ->findOrFail($data['verification_run_id']);

            return $this->traceRecorder->record($run, $data['events']);
        });

        return response()->json(['recorded' => $written]);
    }
}
