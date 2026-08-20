<?php

namespace Database\Seeders;

use App\Domain\Claims\Models\Claim;
use App\Domain\Devices\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Enums\ClaimStatus;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\WorkOrderStatus;
use App\Domain\WorkOrders\Models\WorkOrder;
use App\Services\Organizations\OrganizationProvisioner;
use Illuminate\Database\Seeder;

/**
 * Demo data for the 5-minute run-through.
 *
 * Three work orders, three behaviours:
 *   WO-1042  expected site matches the simulator's observable position -> VERIFIED
 *   WO-1043  expected site is far away                                 -> CONFLICTING -> escalation -> DISPUTED
 *   WO-1044  device with no network coverage in the simulator          -> UNAVAILABLE -> UNVERIFIED
 *
 * The coordinates below are chosen so that a LIVE call to Location
 * Verification against the Nokia Network-as-Code simulator returns the
 * intended result. The simulator reports its test devices around
 * 47.486 N / 19.079 E, so "Site A" sits inside that circle and "Site B"
 * deliberately does not. Override them with SP_DEMO_SITE_* if your
 * simulator account reports a different position.
 */
class DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(OrganizationProvisioner::class);

        $organization = Organization::withoutGlobalScopes()->updateOrCreate(
            ['slug' => 'acme-field-services'],
            [
                'name' => 'Acme Field Services',
                'country' => 'MA',
                'status' => 'ACTIVE',
                'default_policy_key' => 'STANDARD_FIELD_SERVICE',
            ]
        );

        $provisioner->seedPolicies($organization);

        $standard = VerificationPolicy::withoutGlobalScopes()
            ->where('organization_id', $organization->id)->where('key', 'STANDARD_FIELD_SERVICE')->first();
        $highAssurance = VerificationPolicy::withoutGlobalScopes()
            ->where('organization_id', $organization->id)->where('key', 'HIGH_ASSURANCE_FIELD_SERVICE')->first();

        $users = [
            ['admin@acme-field.test', 'Nadia El Amrani', Role::ORG_ADMIN, null],
            ['ops@acme-field.test', 'Youssef Benali', Role::OPERATIONS_MANAGER, null],
            ['review@acme-field.test', 'Lina Haddad', Role::REVIEWER, null],
            ['tech@acme-field.test', 'Karim Ziani', Role::FIELD_WORKER, 'TECH-001'],
            ['tech2@acme-field.test', 'Sofia Mansouri', Role::FIELD_WORKER, 'TECH-002'],
        ];

        $created = [];

        foreach ($users as [$email, $name, $role, $reference]) {
            $created[$email] = User::withoutGlobalScopes()->updateOrCreate(
                ['email' => $email],
                [
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'password' => 'password',
                    'role' => $role->value,
                    'status' => 'ACTIVE',
                    'employee_reference' => $reference,
                ]
            );
        }

        // ── Devices: Nokia NaC simulator identifiers ───────────────────────
        // Documented simulator numbers, so the whole loop can run live without
        // any real SIM.
        //
        // Each number has its own position in the simulator — they are not one
        // shared location. Observed live against Site A (50.735851 / 7.10066):
        //
        //   +99999991001  inside the area  -> the clean VERIFIED case
        //   +99999991000  outside it       -> the contested DISPUTED case
        //   +99999990400  HTTP 500 / 400   -> the no-evidence UNVERIFIED case
        //
        // So the numbers are assigned by the outcome each one produces, which
        // is why DEV-001 carries ...1001 rather than the lower number. Moving
        // the sites around cannot substitute for this: ...1000 reports FALSE
        // at both candidate sites.
        $devices = [
            ['DEV-001', 'Field tablet — Karim', '+99999991001', $created['tech@acme-field.test']],
            ['DEV-002', 'Field phone — Sofia', '+99999991000', $created['tech2@acme-field.test']],
            ['DEV-003', 'Spare handset', '+99999990400', null],
        ];

        $deviceModels = [];

        foreach ($devices as [$reference, $label, $identifier, $owner]) {
            $deviceModels[$reference] = Device::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'reference' => $reference],
                [
                    'user_id' => $owner?->id,
                    'label' => $label,
                    'identifier_type' => 'PHONE_NUMBER',
                    'network_identifier' => $identifier,
                    'is_simulator' => true,
                    'status' => 'ACTIVE',
                ]
            );
        }

        // Site A sits on the Nokia simulator's reported position, so WO-1042
        // verifies cleanly on one call. Site B is deliberately ~940 km away,
        // so WO-1043 conflicts and the agent has to escalate.
        //
        // These coordinates are observed against the live simulator, not taken
        // from the written guide — the guide's position was out of date and
        // inverted the whole demo.
        $siteALat = (float) env('SP_DEMO_SITE_A_LAT', 50.735851);
        $siteALng = (float) env('SP_DEMO_SITE_A_LNG', 7.10066);

        $workOrders = [
            [
                'reference' => 'WO-1042',
                'customer_name' => 'ABC Telecom Services',
                'service_type' => 'Network equipment repair',
                'description' => 'Replace faulty street cabinet module and re-test the link.',
                'site_name' => 'Site A — Central Depot',
                'site_latitude' => $siteALat,
                'site_longitude' => $siteALng,
                'site_radius_m' => 1000,
                'assigned_user_id' => $created['tech@acme-field.test']->id,
                'device_id' => $deviceModels['DEV-001']->id,
                'policy_id' => $standard?->id,
                'risk_level' => 'NORMAL',
                'status' => WorkOrderStatus::IN_PROGRESS->value,
            ],
            [
                'reference' => 'WO-1043',
                'customer_name' => 'Meridian Facilities',
                'service_type' => 'HVAC maintenance',
                'description' => 'Quarterly maintenance visit on the rooftop unit.',
                // Deliberately far from the simulator's observable position:
                // this is the conflicting-evidence scenario.
                'site_name' => 'Site B — Northern Plant',
                'site_latitude' => (float) env('SP_DEMO_SITE_B_LAT', 47.48627616952785),
                'site_longitude' => (float) env('SP_DEMO_SITE_B_LNG', 19.07915612501993),
                'site_radius_m' => 500,
                'assigned_user_id' => $created['tech2@acme-field.test']->id,
                'device_id' => $deviceModels['DEV-002']->id,
                'policy_id' => $highAssurance?->id,
                'risk_level' => 'HIGH',
                'status' => WorkOrderStatus::IN_PROGRESS->value,
            ],
            [
                'reference' => 'WO-1044',
                'customer_name' => 'Atlas Utilities',
                'service_type' => 'Meter inspection',
                'description' => 'Inspect and photograph the substation meter.',
                'site_name' => 'Site C — Remote Substation',
                'site_latitude' => (float) env('SP_DEMO_SITE_C_LAT', 33.5731),
                'site_longitude' => (float) env('SP_DEMO_SITE_C_LNG', -7.5898),
                'site_radius_m' => 1500,
                'assigned_user_id' => $created['tech@acme-field.test']->id,
                'device_id' => $deviceModels['DEV-003']->id,
                'policy_id' => $standard?->id,
                'risk_level' => 'NORMAL',
                'status' => WorkOrderStatus::IN_PROGRESS->value,
            ],
        ];

        // Each work order arrives with a claim already submitted and awaiting
        // verification. Without this the console opens completely empty: there
        // is nothing to verify, and `serviceproof:verify WO-1042` has no claim
        // to act on. The demo starts at the interesting moment instead — a
        // technician has said the job is done, and nobody has checked yet.
        $claimNotes = [
            'WO-1042' => 'Replaced the cabinet module and re-tested the link. Signal is clean.',
            'WO-1043' => 'Completed the rooftop unit service. Filters changed, unit restarted.',
            'WO-1044' => 'Meter inspected and photographed. No faults found.',
        ];

        foreach ($workOrders as $attributes) {
            $workOrder = WorkOrder::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'reference' => $attributes['reference']],
                $attributes + [
                    'organization_id' => $organization->id,
                    'scheduled_at' => now()->subMinutes(90),
                    'window_starts_at' => now()->subHours(2),
                    'window_ends_at' => now()->addHours(2),
                ]
            );

            $reference = str_replace('WO-', 'CLM-', $workOrder->reference);

            Claim::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'reference' => $reference],
                [
                    'organization_id' => $organization->id,
                    'work_order_id' => $workOrder->id,
                    'user_id' => $workOrder->assigned_user_id,
                    'device_id' => $workOrder->device_id,
                    'claim_type' => 'SERVICE_COMPLETED',
                    // Inside the service window, so device signals observed now
                    // count as contemporaneous evidence rather than context.
                    'claimed_at' => now()->subMinutes(20),
                    'notes' => $claimNotes[$workOrder->reference] ?? null,
                    'status' => ClaimStatus::SUBMITTED->value,
                    'submitted_at' => now()->subMinutes(18),
                ]
            );
        }

        // A second tenant, purely to prove isolation in the demo.
        $second = Organization::withoutGlobalScopes()->updateOrCreate(
            ['slug' => 'northwind-utilities'],
            ['name' => 'Northwind Utilities', 'country' => 'AE', 'status' => 'ACTIVE', 'default_policy_key' => 'STANDARD_FIELD_SERVICE']
        );

        $provisioner->seedPolicies($second);

        User::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'admin@northwind.test'],
            [
                'organization_id' => $second->id,
                'name' => 'Northwind Admin',
                'password' => 'password',
                'role' => Role::ORG_ADMIN->value,
                'status' => 'ACTIVE',
            ]
        );

        $this->command?->info('Demo data ready: WO-1042 (verified), WO-1043 (disputed), WO-1044 (unverified).');
        $this->command?->line('Logins — ops@acme-field.test / tech@acme-field.test / review@acme-field.test — password: password');
    }
}
