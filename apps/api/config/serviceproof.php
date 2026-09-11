<?php

/*
|--------------------------------------------------------------------------
| ServiceProof — assurance configuration
|--------------------------------------------------------------------------
| These values are the deterministic half of the system. The AI agent may
| decide WHICH evidence to gather; this file (and the per-organisation
| policies stored in the database) decide WHAT IS SUFFICIENT.
*/

return [

    'default_policy' => env('SP_DEFAULT_POLICY', 'STANDARD_FIELD_SERVICE'),

    // Evidence older than this is downgraded to STALE by the evidence engine.
    'evidence_max_age_seconds' => (int) env('SP_EVIDENCE_MAX_AGE_SECONDS', 900),

    // Data lifecycle. No mandatory retention period is imposed by the
    // hackathon material, so nothing is hard-coded in the domain layer.
    'retention_days' => (int) env('SP_RETENTION_DAYS', 365),

    /*
    | Built-in policy templates. Seeded per organisation and editable by an
    | ORG_ADMIN. `assurance_level` drives the default evidence budget.
    */
    'policy_templates' => [

        'STANDARD_FIELD_SERVICE' => [
            'name' => 'Standard field service',
            'description' => 'Routine interventions. Location consistency is normally sufficient.',
            'assurance_level' => 'STANDARD',
            'required_evidence' => ['LOCATION_VERIFICATION'],
            // Device Swap first, deliberately.
            //
            // When location is contested, "was the handset on the network" is
            // true of almost every handset and answers nothing. "Has the device
            // behind this subscription changed recently" bears directly on the
            // assumption the claim rests on: that this identifier still maps to
            // this worker's device.
            //
            // The escalation ladder is policy, not planner logic, so the order
            // lives here where an operations team can reason about it.
            'optional_evidence' => ['DEVICE_SWAP', 'DEVICE_STATUS', 'DEVICE_REACHABILITY'],
            // Three, not two.
            //
            // Location alone satisfies this policy, so a routine claim still
            // costs one call — the budget is a ceiling, not a target. But when
            // the first signal conflicts, the agent needs room to reach for
            // both corroborating signals before it gives up. At two it ran out
            // of budget mid-escalation, which reads as a truncated loop rather
            // than a reasoned one.
            'max_tool_calls' => 3,
            'max_latency_ms' => 12000,
            'location_radius_m' => 1000,
            'freshness_seconds' => 900,
            'allow_partial' => true,
            'auto_close_on_verified' => true,
        ],

        'HIGH_ASSURANCE_FIELD_SERVICE' => [
            'name' => 'High assurance intervention',
            'description' => 'Critical or high-value work. Corroborating device evidence required.',
            'assurance_level' => 'HIGH',
            'required_evidence' => ['LOCATION_VERIFICATION', 'DEVICE_STATUS'],
            'optional_evidence' => ['DEVICE_REACHABILITY'],
            'max_tool_calls' => 3,
            'max_latency_ms' => 20000,
            'location_radius_m' => 500,
            'freshness_seconds' => 600,
            'allow_partial' => false,
            'auto_close_on_verified' => false,
        ],

        'LIGHT_TOUCH' => [
            'name' => 'Light touch',
            'description' => 'Low-risk visits. One signal, no escalation.',
            'assurance_level' => 'LOW',
            'required_evidence' => ['LOCATION_VERIFICATION'],
            'optional_evidence' => [],
            'max_tool_calls' => 1,
            'max_latency_ms' => 8000,
            'location_radius_m' => 2000,
            'freshness_seconds' => 1800,
            'allow_partial' => true,
            'auto_close_on_verified' => true,
        ],
    ],

    /*
    | Hard invariants enforced by the DecisionGuard on the way back from the
    | agent runtime. The agent proposes; Laravel disposes.
    */
    'guards' => [
        // A claim can never be auto-VERIFIED while any evidence conflicts.
        'conflict_blocks_verification' => true,
        // Zero usable evidence can never produce anything but UNVERIFIED.
        'no_evidence_forces_unverified' => true,
        // Demo-fallback evidence may still produce a decision, but the
        // decision record is flagged so nobody mistakes it for live data.
        'flag_simulated_decisions' => true,
    ],

    /*
    | Which CAMARA capabilities this deployment is allowed to expose to the
    | agent as tools. Anything not listed here is never offered, whatever the
    | agent asks for.
    */
    /*
     * What a field-service entitlement covers by default.
     *
     * Deliberately narrow: the two capabilities the demonstration policy
     * actually needs. Widening it is a product decision with a consent
     * dimension, which is why it is configuration rather than a constant.
     */
    'entitlement' => [
        'default_capabilities' => [
            'LOCATION_VERIFICATION',
            'DEVICE_SWAP',
        ],
    ],

    'enabled_tools' => [
        'verify_location',
        'check_device_swap',
        'get_device_status',
        'check_reachability',
    ],
];
