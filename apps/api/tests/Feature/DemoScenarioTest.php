<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The seeded demonstration must keep proving what the submission claims.
 *
 * The whole Phase-2 argument is: same claim, same policy, different network
 * evidence, different agent behaviour. That only holds if both demonstration
 * work orders share a policy and that policy leaves room to escalate twice.
 *
 * This test exists because changing the budget in config and reseeding once
 * appeared to do nothing: a cached config silently overrode the file, the
 * seeder's own code took effect and the config-derived values did not, and
 * nothing anywhere reported a disagreement. A test reads the config the same
 * way the provisioner does, so a stale cache or an edited value fails here
 * instead of on stage.
 */
class DemoScenarioTest extends TestCase
{
    public function test_the_standard_policy_leaves_room_for_two_escalations(): void
    {
        $standard = config('serviceproof.policy_templates.STANDARD_FIELD_SERVICE');

        $this->assertNotNull($standard, 'STANDARD_FIELD_SERVICE must exist.');

        // One required signal plus two escalations. At two the agent runs out
        // of budget mid-escalation, which reads as a truncated loop rather
        // than a reasoned one.
        $this->assertSame(3, $standard['max_tool_calls']);
        $this->assertSame(['LOCATION_VERIFICATION'], $standard['required_evidence']);
        $this->assertSame(
            ['DEVICE_STATUS', 'DEVICE_REACHABILITY'],
            $standard['optional_evidence'],
            'Both corroborating signals must be reachable by escalation.'
        );
    }

    public function test_a_routine_claim_still_costs_one_call(): void
    {
        $standard = config('serviceproof.policy_templates.STANDARD_FIELD_SERVICE');

        // The budget is a ceiling, not a target: location alone satisfies the
        // policy, so a clean claim stops at one call however large the budget.
        $this->assertCount(1, $standard['required_evidence']);
    }

    public function test_the_two_demonstration_work_orders_share_a_policy(): void
    {
        $seeder = file_get_contents(
            database_path('seeders/DemoOrganizationSeeder.php')
        );

        // WO-1043 must sit on $standard, not $highAssurance. With different
        // policies a reviewer could fairly say the escalation was simply the
        // second policy's minimum being met — which is the objection the
        // demonstration exists to close.
        $block = substr(
            $seeder,
            strpos($seeder, "'reference' => 'WO-1043'"),
            strpos($seeder, "'reference' => 'WO-1044'") - strpos($seeder, "'reference' => 'WO-1043'")
        );

        $this->assertStringContainsString("'policy_id' => \$standard?->id", $block);
        $this->assertStringNotContainsString('highAssurance', $block);
    }
}
