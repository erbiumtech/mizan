<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Bank;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Employees\Models\Employee;
use App\Support\CompanyDates;
use App\Support\StatutoryCoverage;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The company details the walkthrough had and this lacked — docs/saas-launch-plan.md §14.10.
 *
 * Small fields, each with one judgement in it worth pinning: which branch a document
 * comes from, what an unknown date format does, whether a blank IBAN prefix refuses
 * anything, and whether the coverage page is allowed to claim compliance.
 */
class CompanyDetailFieldsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'details@test.local'));
        $this->setCurrentTenant();
    }

    // ------------------------------------------------------------------ branches

    public function test_only_one_branch_is_the_head_office(): void
    {
        $lahore = WorkLocation::create(['name' => 'Lahore', 'is_head_office' => true, 'is_active' => true]);
        $karachi = WorkLocation::create(['name' => 'Karachi', 'is_active' => true]);

        $this->assertTrue($lahore->refresh()->is_head_office);

        $karachi->update(['is_head_office' => true]);

        $this->assertFalse($lahore->refresh()->is_head_office, 'the last one to say so wins');
        $this->assertTrue($karachi->refresh()->is_head_office);
        $this->assertTrue(WorkLocation::headOffice()->is($karachi));
    }

    public function test_a_company_that_never_chose_a_head_office_still_has_one(): void
    {
        WorkLocation::create(['name' => 'Karachi', 'is_active' => true]);
        $first = WorkLocation::create(['name' => 'Aabpara', 'is_active' => true]);

        $this->assertTrue(
            WorkLocation::headOffice()->is($first),
            'the first active branch by name — right for the company with one, and a starting point for the rest',
        );
    }

    // --------------------------------------------------------------------- banks

    public function test_an_iban_is_checked_against_the_bank_that_claims_the_prefix(): void
    {
        $standard = Bank::create(['bank_code' => '01', 'bank_name' => 'Standard Chartered', 'iban_prefix' => 'SCBL']);
        $unknown = Bank::create(['bank_code' => '02', 'bank_name' => 'Somebody Else']);

        $this->assertTrue($standard->ownsIban('PK36SCBL0000001123456702'));
        $this->assertTrue($standard->ownsIban('pk36 scbl 0000 0011 2345 6702'), 'spacing and case are not the point');
        $this->assertFalse($standard->ownsIban('PK36MUCB0000001123456702'));

        $this->assertNull(
            $unknown->ownsIban('PK36SCBL0000001123456702'),
            'an unknown prefix cannot refuse anything — a directory that rejected accounts because a column '
            .'was blank would be worse than one that checks nothing',
        );
        $this->assertNull($standard->ownsIban(null));
        $this->assertNull($standard->ownsIban('PK36'), 'too short to say');
    }

    // --------------------------------------------------------------- date format

    public function test_an_unknown_date_format_falls_back_rather_than_printing_itself(): void
    {
        $settings = app(TenantSettings::class);

        $this->assertSame(CompanyDates::DEFAULT, CompanyDates::format(), 'nothing chosen is the shipped one');

        $settings->set('company.date_format', 'd/m/Y');
        $this->assertSame('09/03/2026', CompanyDates::write('2026-03-09'));

        // The one failure that matters: a format string reaching a payslip.
        $settings->set('company.date_format', 'Y-m-d H:i:s');
        $this->assertSame('09 Mar 2026', CompanyDates::write('2026-03-09'));

        $this->assertSame('', CompanyDates::write(null), 'a null column prints nothing, never 1970');
    }

    // ---------------------------------------------------------- employee codes

    public function test_the_code_prefix_comes_from_the_setting(): void
    {
        $this->assertSame('EMP-0001', Employee::nextEmployeeId());

        app(TenantSettings::class)->set('employees.code_prefix', 'MZN-');

        $this->assertSame('MZN-0001', Employee::nextEmployeeId());

        Employee::create(['employee_id' => 'MZN-0001', 'name' => 'First', 'gender' => 'Male', 'is_active' => true]);

        $this->assertSame('MZN-0002', Employee::nextEmployeeId());
    }

    public function test_changing_the_prefix_starts_a_sequence_rather_than_renumbering(): void
    {
        Employee::create(['employee_id' => 'EMP-0042', 'name' => 'Old', 'gender' => 'Male', 'is_active' => true]);

        app(TenantSettings::class)->set('employees.code_prefix', 'MZN-');

        $this->assertSame(
            'MZN-0001',
            Employee::nextEmployeeId(),
            'the next code is the highest in use *with that prefix*, so a switch starts at one',
        );
    }

    public function test_a_blank_prefix_falls_back_rather_than_numbering_from_nothing(): void
    {
        app(TenantSettings::class)->set('employees.code_prefix', '   ');

        $this->assertSame('EMP-0001', Employee::nextEmployeeId());
    }

    // --------------------------------------------------------- statutory coverage

    public function test_the_coverage_page_describes_the_software_and_never_claims_compliance(): void
    {
        $entries = StatutoryCoverage::all();

        $this->assertNotEmpty($entries, 'the modules register their own');

        foreach ($entries as $entry) {
            $this->assertNotSame('', trim($entry['act']));
            $this->assertNotSame('', trim($entry['does']));

            // The wording rule the registry exists to keep. "Complies with" is a statement
            // about a company's affairs, and printing one would be worse than printing
            // nothing, because somebody would rely on it.
            foreach (['complies', 'compliant', 'compliance with', 'guarantees', 'certified'] as $claim) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $claim,
                    $entry['does'],
                    "[{$entry['act']}] says what the software does, never that anybody complies",
                );
            }
        }
    }

    public function test_coverage_says_which_entries_need_a_module_this_company_lacks(): void
    {
        $withModules = collect(StatutoryCoverage::all())->whereNotNull('module');

        $this->assertNotEmpty($withModules);
        $this->assertTrue(
            $withModules->contains(fn (array $entry): bool => $entry['available'] === false)
                || $withModules->every(fn (array $entry): bool => $entry['available'] === true),
            'availability is answered per entry rather than assumed',
        );
    }
}
