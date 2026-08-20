<?php

namespace App\Services\Metrics;

use App\Domain\Claims\Models\Claim;
use App\Domain\Decisions\Models\Decision;
use App\Domain\Evidence\Models\Evidence;
use App\Domain\Reviews\Models\Review;
use App\Domain\Shared\Enums\DecisionState;
use App\Domain\Verification\Models\VerificationRun;
use Illuminate\Support\Carbon;

class OperationsMetrics
{
    /**
     * Dashboard figures. Every number here is derived from real records —
     * nothing is a marketing estimate.
     */
    public function overview(?Carbon $since = null): array
    {
        $since ??= now()->subDays(30);

        $claims = Claim::where('created_at', '>=', $since)->count();

        $byState = Decision::where('is_current', true)
            ->where('created_at', '>=', $since)
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $verified = (int) ($byState[DecisionState::VERIFIED->value] ?? 0);
        $partial = (int) ($byState[DecisionState::PARTIAL->value] ?? 0);
        $disputed = (int) ($byState[DecisionState::DISPUTED->value] ?? 0);
        $unverified = (int) ($byState[DecisionState::UNVERIFIED->value] ?? 0);
        $decided = $verified + $partial + $disputed + $unverified;

        $runs = VerificationRun::where('created_at', '>=', $since);
        $avgDuration = (int) round((clone $runs)->avg('duration_ms') ?? 0);
        $escalations = (clone $runs)->where('escalated', true)->count();
        $totalRuns = (clone $runs)->count();
        $toolCalls = (int) ((clone $runs)->sum('tool_calls_used'));

        return [
            'window' => ['since' => $since->toIso8601String(), 'until' => now()->toIso8601String()],
            'claims' => [
                'total' => $claims,
                'decided' => $decided,
            ],
            'decisions' => [
                'verified' => $verified,
                'partial' => $partial,
                'disputed' => $disputed,
                'unverified' => $unverified,
            ],
            'automation' => [
                // Share of claims resolved without a human reviewer.
                'auto_verification_rate' => $decided > 0 ? round($verified / $decided, 4) : null,
                'dispute_rate' => $decided > 0 ? round($disputed / $decided, 4) : null,
                'escalation_rate' => $totalRuns > 0 ? round($escalations / $totalRuns, 4) : null,
                'avg_tool_calls_per_run' => $totalRuns > 0 ? round($toolCalls / $totalRuns, 2) : null,
                'avg_decision_ms' => $avgDuration,
            ],
            'reviews' => [
                'open' => Review::pending()->count(),
                'resolved' => Review::where('status', 'RESOLVED')->where('created_at', '>=', $since)->count(),
            ],
            'evidence' => [
                'total' => Evidence::where('created_at', '>=', $since)->count(),
                'simulated' => Evidence::where('created_at', '>=', $since)->where('source', 'DEMO_FALLBACK')->count(),
                'by_status' => Evidence::where('created_at', '>=', $since)
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ],
        ];
    }

    /**
     * Illustrative operational ROI. Explicitly a model, not a measured
     * production result — the assumptions travel with the numbers.
     */
    public function roi(int $minutesPerManualCheck = 5, float $hourlyCost = 25.0, ?Carbon $since = null): array
    {
        $since ??= now()->subDays(30);

        $decided = Decision::where('is_current', true)->where('created_at', '>=', $since)->count();
        $auto = Decision::where('is_current', true)
            ->where('created_at', '>=', $since)
            ->where('state', DecisionState::VERIFIED->value)
            ->count();

        $minutesAvoided = $auto * $minutesPerManualCheck;

        return [
            'assumptions' => [
                'minutes_per_manual_check' => $minutesPerManualCheck,
                'internal_hourly_cost' => $hourlyCost,
                'note' => 'Illustrative model based on this deployment\'s own records, not a claimed production result.',
            ],
            'claims_decided' => $decided,
            'auto_resolved' => $auto,
            'manual_minutes_avoided' => $minutesAvoided,
            'manual_hours_avoided' => round($minutesAvoided / 60, 1),
            'estimated_cost_avoided' => round(($minutesAvoided / 60) * $hourlyCost, 2),
        ];
    }
}
