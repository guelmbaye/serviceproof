<?php

namespace App\Console\Commands;

use App\Domain\Claims\Models\Claim;
use App\Services\Verification\VerificationService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Demo-day utility: run the full assurance loop from the terminal, without
 * the web UI. Handy for the pre-presentation rehearsal checklist.
 */
class VerifyClaimCommand extends Command
{
    protected $signature = 'serviceproof:verify
                            {claim : Claim reference (e.g. CLM-1001) or UUID}
                            {--mode= : Force the evidence adapter (live|demo)}
                            {--scenario= : Demo scenario (VERIFIED|DISPUTED|UNVERIFIED)}';

    protected $description = 'Run the verification loop for a claim and print the decision trace';

    public function handle(VerificationService $verification, TenantContext $tenant): int
    {
        $identifier = $this->argument('claim');

        $claim = $tenant->withoutScope(fn () => Claim::withoutGlobalScope('organization')
            ->where('reference', $identifier)
            ->orWhere('id', $identifier)
            ->first());

        if (! $claim) {
            $this->error("Claim {$identifier} not found.");

            return self::FAILURE;
        }

        $tenant->set($claim->organization_id);

        $run = $verification->verify($claim, null, array_filter([
            'force_mode' => $this->option('mode'),
            'scenario' => $this->option('scenario'),
        ]));

        $this->newLine();
        $this->line('<comment>Decision trace</comment>');

        foreach ($run->traceEvents as $event) {
            $this->line(sprintf(
                '  %s  %-22s %s',
                $event->occurred_at->format('H:i:s.v'),
                $event->event_type->value,
                $event->label
            ));
        }

        $decision = $run->decision;

        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Claim', $claim->reference],
            ['Policy', $run->policy?->key],
            ['Tool calls', $run->tool_calls_used.' / '.$run->budget_max_tool_calls],
            ['Escalated', $run->escalated ? 'yes' : 'no'],
            ['Evidence', $run->evidence->count()],
            ['Decision', $decision?->state->value],
            ['Assurance', $decision?->assurance_score.'%'],
            ['Simulated', $decision?->simulated ? 'YES — demo fallback' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
