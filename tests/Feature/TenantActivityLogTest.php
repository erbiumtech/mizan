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

    /**
     * A mis-encoded byte anywhere in the properties must not abort the write.
     *
     * `json_encode()` rejects malformed UTF-8, and the `collection` cast raises that as a
     * `JsonEncodingException` during the *assignment* — inside the audited model's
     * `created` event, and therefore inside its transaction. One bad byte in an employee
     * name was enough to fail a whole payroll posting, so the properties are repaired on
     * the way in and the log is written with the broken byte replaced.
     */
    public function test_malformed_utf8_in_properties_is_repaired_rather_than_thrown(): void
    {
        $company = Company::factory()->create();
        $this->setCurrent($company);

        // "\xE9" is a lone Windows-1252 "é" — valid latin1, invalid UTF-8. The em dash
        // either side of it is legitimate multi-byte UTF-8 and must survive intact.
        activity()
            ->withProperties([
                'memo' => "Reversal of JE-000123 — Payroll — Jos\xE9",
                'nested' => ['name' => "Mu\xF1oz", 'kept' => 'José 日本 🙂'],
            ])
            ->log('reversal');

        $stored = ActivityLog::first();

        $this->assertNotNull($stored);

        $raw = $stored->getAttributes()['properties'];
        $this->assertTrue(mb_check_encoding($raw, 'UTF-8'));
        $this->assertNotNull(json_decode($raw, true), 'stored properties must be decodable JSON');

        // The valid characters are untouched; only the broken bytes are substituted.
        $this->assertStringContainsString('—', $stored->properties['memo']);
        $this->assertStringStartsWith('Reversal of JE-000123 — Payroll — Jos', $stored->properties['memo']);
        $this->assertSame('José 日本 🙂', $stored->properties['nested']['kept']);
    }

    public function test_null_properties_are_stored_as_sql_null(): void
    {
        $log = new ActivityLog;
        $log->properties = null;

        $this->assertNull($log->getAttributes()['properties']);
    }
}
