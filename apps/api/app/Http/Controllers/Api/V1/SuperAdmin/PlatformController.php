<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Domain\Claims\Models\Claim;
use App\Domain\Evidence\Models\Evidence;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Verification\Models\VerificationRun;
use App\Http\Controllers\Controller;
use App\Services\Agent\AgentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlatformController extends Controller
{
    public function __construct(private readonly AgentGateway $agent) {}

    /** Platform health: product core, agent runtime, network evidence mix. */
    public function health(): JsonResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return response()->json([
            'database' => $this->check(fn () => DB::select('select 1') !== null),
            'cache' => $this->check(function () {
                cache()->put('sp:health', 1, 5);

                return cache()->get('sp:health') === 1;
            }),
            'agent_runtime' => $this->agent->health(),
            'evidence_mix' => [
                'live' => Evidence::withoutGlobalScope('organization')->where('source', 'CAMARA')->count(),
                'demo_fallback' => Evidence::withoutGlobalScope('organization')->where('source', 'DEMO_FALLBACK')->count(),
            ],
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function stats(): JsonResponse
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return response()->json([
            'organizations' => [
                'total' => Organization::count(),
                'active' => Organization::where('status', 'ACTIVE')->count(),
            ],
            'users' => User::count(),
            'claims' => Claim::withoutGlobalScope('organization')->count(),
            'verification_runs' => [
                'total' => VerificationRun::withoutGlobalScope('organization')->count(),
                'escalated' => VerificationRun::withoutGlobalScope('organization')->where('escalated', true)->count(),
                'failed' => VerificationRun::withoutGlobalScope('organization')->where('status', 'FAILED')->count(),
            ],
        ]);
    }

    private function check(callable $probe): array
    {
        try {
            return ['ok' => (bool) $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
