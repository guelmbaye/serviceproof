<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Claims\Models\Claim;
use App\Domain\Reviews\Models\Review;
use App\Domain\Verification\Models\VerificationRun;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\VerificationRunResource;
use App\Services\Metrics\OperationsMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly OperationsMetrics $metrics) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Claim::class);

        $since = now()->subDays($request->integer('days', 30));

        return response()->json([
            'metrics' => $this->metrics->overview($since),
            'recent_verifications' => VerificationRunResource::collection(
                VerificationRun::with(['decision', 'claim.workOrder', 'policy'])->latest()->limit(8)->get()
            ),
            'exceptions' => ReviewResource::collection(
                Review::with(['claim.workOrder', 'decision'])->pending()->latest()->limit(8)->get()
            ),
            'recent_claims' => ClaimResource::collection(
                Claim::with(['workOrder', 'currentDecision', 'user'])->latest()->limit(8)->get()
            ),
        ]);
    }

    public function roi(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Claim::class);

        return response()->json([
            'data' => $this->metrics->roi(
                $request->integer('minutes_per_check', 5),
                (float) $request->input('hourly_cost', 25),
                now()->subDays($request->integer('days', 30)),
            ),
        ]);
    }
}
