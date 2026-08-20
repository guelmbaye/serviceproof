<?php

namespace Tests;

use App\Domain\Devices\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Enums\Role;
use App\Domain\WorkOrders\Models\WorkOrder;
use App\Services\Organizations\OrganizationProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function createOrganization(string $name = 'Test Org'): Organization
    {
        $organization = Organization::withoutGlobalScopes()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => 'ACTIVE',
            'default_policy_key' => 'STANDARD_FIELD_SERVICE',
        ]);

        app(OrganizationProvisioner::class)->seedPolicies($organization);

        return $organization;
    }

    protected function createUser(Organization $organization, Role $role, array $attributes = []): User
    {
        return User::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'User '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'password-secret',
            'role' => $role->value,
            'status' => 'ACTIVE',
            'employee_reference' => 'TECH-'.random_int(100, 999),
        ], $attributes));
    }

    protected function createWorkOrder(Organization $organization, User $worker, array $attributes = []): WorkOrder
    {
        $device = Device::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $worker->id,
            'reference' => 'DEV-'.random_int(100, 999),
            'identifier_type' => 'PHONE_NUMBER',
            'network_identifier' => '+99999991000',
            'is_simulator' => true,
            'status' => 'ACTIVE',
        ]);

        $policy = VerificationPolicy::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('key', 'STANDARD_FIELD_SERVICE')
            ->first();

        return WorkOrder::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $organization->id,
            'reference' => 'WO-'.random_int(1000, 9999),
            'customer_name' => 'Test Customer',
            'site_name' => 'Site A',
            'site_latitude' => 47.4862761,
            'site_longitude' => 19.0791561,
            'site_radius_m' => 1000,
            'assigned_user_id' => $worker->id,
            'device_id' => $device->id,
            'policy_id' => $policy?->id,
            'risk_level' => 'NORMAL',
            'status' => 'IN_PROGRESS',
            'scheduled_at' => now()->subHour(),
        ], $attributes));
    }
}
