<?php

namespace Tests\Unit;

use App\Support\ReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /** Insert bare reference rows without paying for a full work order each. */
    private function seedReferences(string $organizationId, array $references): void
    {
        foreach ($references as $reference) {
            DB::table('work_orders')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $organizationId,
                'reference' => $reference,
                'customer_name' => 'Test Customer',
                'site_name' => 'Site A',
                'risk_level' => 'NORMAL',
                'status' => 'SCHEDULED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_it_starts_at_the_configured_number_when_nothing_exists(): void
    {
        $organization = $this->createOrganization();

        $this->assertSame(
            'WO-1001',
            ReferenceGenerator::next('work_orders', $organization->id, 'WO')
        );
    }

    public function test_it_increments_from_the_highest_existing_reference(): void
    {
        $organization = $this->createOrganization();
        $this->seedReferences($organization->id, ['WO-1001', 'WO-1002', 'WO-1003']);

        $this->assertSame(
            'WO-1004',
            ReferenceGenerator::next('work_orders', $organization->id, 'WO')
        );
    }

    /**
     * The regression this test exists for.
     *
     * A plain string sort puts 'WO-9999' above 'WO-10000', so the generator
     * would read 9999 and hand out 'WO-10000' a second time — straight into a
     * unique constraint violation on the ten thousandth record.
     */
    public function test_it_orders_numerically_past_the_digit_boundary(): void
    {
        $organization = $this->createOrganization();
        $this->seedReferences($organization->id, [
            'WO-9998', 'WO-9999', 'WO-10000', 'WO-10001',
        ]);

        $this->assertSame(
            'WO-10002',
            ReferenceGenerator::next('work_orders', $organization->id, 'WO')
        );
    }

    public function test_it_crosses_the_thousand_boundary_too(): void
    {
        $organization = $this->createOrganization();
        $this->seedReferences($organization->id, ['WO-998', 'WO-999', 'WO-1000']);

        $this->assertSame(
            'WO-1001',
            ReferenceGenerator::next('work_orders', $organization->id, 'WO')
        );
    }

    /** References are per tenant: one organisation's numbering cannot leak. */
    public function test_numbering_is_scoped_to_the_organisation(): void
    {
        $first = $this->createOrganization('First Tenant');
        $second = $this->createOrganization('Second Tenant');

        $this->seedReferences($first->id, ['WO-1001', 'WO-50000']);

        $this->assertSame(
            'WO-50001',
            ReferenceGenerator::next('work_orders', $first->id, 'WO')
        );
        $this->assertSame(
            'WO-1001',
            ReferenceGenerator::next('work_orders', $second->id, 'WO'),
            'A second tenant starts its own sequence.'
        );
    }

    /** A different prefix in the same table keeps its own run of numbers. */
    public function test_prefixes_are_counted_separately(): void
    {
        $organization = $this->createOrganization();
        $this->seedReferences($organization->id, ['WO-1001', 'WO-1002', 'EMG-4000']);

        $this->assertSame(
            'WO-1003',
            ReferenceGenerator::next('work_orders', $organization->id, 'WO')
        );
        $this->assertSame(
            'EMG-4001',
            ReferenceGenerator::next('work_orders', $organization->id, 'EMG')
        );
    }

    /**
     * The lock is held inside the caller's transaction, so generation still
     * works when wrapped in one — which is how ClaimService and the work-order
     * controller both call it.
     */
    public function test_it_works_inside_a_transaction(): void
    {
        $organization = $this->createOrganization();
        $this->seedReferences($organization->id, ['WO-1001']);

        $reference = DB::transaction(
            fn () => ReferenceGenerator::next('work_orders', $organization->id, 'WO')
        );

        $this->assertSame('WO-1002', $reference);
    }

    /** 40 consecutive references, across the digit boundary, no repeats. */
    public function test_a_long_run_never_repeats_a_reference(): void
    {
        $organization = $this->createOrganization();
        $issued = [];

        $reference = ReferenceGenerator::next('work_orders', $organization->id, 'WO', 998);

        for ($i = 0; $i < 40; $i++) {
            $this->assertNotContains($reference, $issued, "Repeated $reference");
            $issued[] = $reference;

            $this->seedReferences($organization->id, [$reference]);
            $reference = ReferenceGenerator::next('work_orders', $organization->id, 'WO', 998);
        }

        $this->assertSame('WO-998', $issued[0]);
        $this->assertSame('WO-1037', $issued[39]);
        $this->assertCount(40, array_unique($issued));
    }
}
