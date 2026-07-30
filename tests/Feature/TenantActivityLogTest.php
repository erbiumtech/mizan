<?php

namespace Tests\Feature;

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setCurrent(?Company $company): void
    {
        if ($company) {
            app()->instance('currentTenant', $company);
        } else {
            app()->forgetInstance('currentTenant');
        }
    }

    protected function tearDown(): void
    {
        $this->setCurrent(null);
        parent::tearDown();
    }

    public function test_activity_is_stamped_and_scoped_per_company(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();

        $this->setCurrent($a);
        activity()->log('in A');

        $this->setCurrent($b);
        activity()->log('in B');

        // Reads are scoped to the current company.
        $this->assertSame(1, ActivityLog::count());
        $this->assertSame('in B', ActivityLog::first()->description);
        $this->assertSame($b->id, ActivityLog::first()->company_id);

        $this->setCurrent($a);
        $this->assertSame(1, ActivityLog::count());
        $this->assertSame($a->id, ActivityLog::first()->company_id);

        // Landlord context (no tenant) sees everything.
        $this->setCurrent(null);
        $this->assertSame(2, ActivityLog::count());
    }
}
