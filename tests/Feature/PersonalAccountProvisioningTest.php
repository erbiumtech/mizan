<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Multitenancy\CompanyProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A personal account is a tenant like any other, and that is the whole design.
 *
 * Somebody keeping their own books may want an accountant to do it for them,
 * and may employ a driver or a cook. Both of those need roles and staff records
 * — machinery this app already has, per tenant. So rather than build a private
 * per-user ledger and then bolt roles onto it, a personal account gets its own
 * database, its own five roles and its own chart of accounts, and the only
 * things that differ are what it is called, which modules it starts with, and
 * which accounts it is seeded with.
 */
class PersonalAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $provisionedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Provisioning requires a dedicated, switchable tenant connection.
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach ($this->provisionedFiles as $path) {
            if ($path && File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    private function provisionPersonal(?User $owner = null): Company
    {
        $company = app(CompanyProvisioner::class)->provision(
            name: 'Muzafar Household',
            creator: $owner ?? User::factory()->create(),
            type: Company::TYPE_PERSONAL,
        );

        $this->provisionedFiles[] = $company->database;

        return $company;
    }

    public function test_a_personal_account_is_marked_as_one_and_named_accordingly(): void
    {
        $company = $this->provisionPersonal();

        $this->assertTrue($company->isPersonal());
        $this->assertSame('Personal Account', $company->typeLabel());
    }

    public function test_a_business_is_still_the_default(): void
    {
        $company = app(CompanyProvisioner::class)->provision(name: 'Acme Ltd');
        $this->provisionedFiles[] = $company->database;

        $this->assertFalse($company->isPersonal());
        $this->assertSame('Company', $company->typeLabel());
    }

    public function test_it_gets_the_same_five_roles_as_any_other_tenant(): void
    {
        // The reason this design works: an accountant, a manager and staff are
        // all just roles, and roles are already per tenant.
        $company = $this->provisionPersonal();

        $roles = Role::where('company_id', $company->getKey())->pluck('name')->sort()->values()->all();

        $this->assertSame(['Accountant', 'Administrator', 'CEO', 'Employee', 'Manager'], $roles);
    }

    public function test_the_owner_is_administrator_of_their_own_account(): void
    {
        $owner = User::factory()->create();
        $company = $this->provisionPersonal($owner);

        $company->makeCurrent();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $this->assertTrue($owner->fresh()->hasRole('Administrator'));

        Company::forgetCurrent();
    }

    public function test_it_starts_with_a_household_chart_of_accounts(): void
    {
        $company = $this->provisionPersonal();
        $company->makeCurrent();

        // The categories a household actually spends on, including the two the
        // requirement named: education, and paying domestic staff.
        $this->assertNotNull(Account::where('code', '5300')->first(), 'Education');
        $this->assertNotNull(Account::where('code', '5350')->first(), 'Domestic Staff Wages');
        $this->assertNotNull(Account::where('code', '1000')->first(), 'Cash in Hand');

        // And not the business chart: no receivables to chase, no sales tax.
        $this->assertNull(Account::where('name', 'Accounts Receivable')->first());

        // The two codes the accounting module looks up by name. A personal
        // account missing these breaks on the first opening balance or year close.
        $this->assertNotNull(Account::where('code', Account::OPENING_BALANCE_EQUITY_CODE)->first());
        $this->assertNotNull(Account::where('code', Account::RETAINED_EARNINGS_CODE)->first());

        Company::forgetCurrent();
    }

    public function test_it_starts_with_the_individual_tax_brackets(): void
    {
        $company = $this->provisionPersonal();
        $company->makeCurrent();

        $this->assertGreaterThan(0, TaxSchedule::where('regime', TaxSchedule::REGIME_SALARIED)->count());

        Company::forgetCurrent();
    }

    public function test_it_is_licensed_for_what_a_household_needs_and_not_the_rest(): void
    {
        $company = $this->provisionPersonal();

        $licensed = $company->companyModules()->where('licensed', true)->pluck('module')->all();

        // A ledger, somewhere to record the people employed, and the tax estimate.
        $this->assertContains('accounting', $licensed);
        $this->assertContains('employees', $licensed);
        $this->assertContains('personal_finance', $licensed);

        // Core is always licensed — it holds the Modules page, so a tenant
        // without it cannot administer its way back.
        $this->assertContains('core', $licensed);

        // A household does not invoice customers or run projects. Payroll is
        // absent on purpose too: paying the cook is an expense, not a payslip.
        $this->assertNotContains('invoicing', $licensed);
        $this->assertNotContains('projects', $licensed);
        $this->assertNotContains('mpr', $licensed);
        $this->assertNotContains('payroll', $licensed);
    }

    public function test_a_business_still_gets_the_registry_defaults(): void
    {
        // The personal preset must not have changed what a business starts with.
        $company = app(CompanyProvisioner::class)->provision(name: 'Acme Ltd');
        $this->provisionedFiles[] = $company->database;

        $licensed = $company->companyModules()->where('licensed', true)->pluck('module')->all();

        $this->assertSame(['core'], $licensed);
    }

    public function test_a_household_can_employ_someone_who_never_signs_in(): void
    {
        $company = $this->provisionPersonal();
        $company->makeCurrent();

        $cook = Employee::create([
            'employee_id' => 'DOM-1',
            'name' => 'Rashid',
            'designation' => 'Cook',
            'gender' => 'Male',
            'is_active' => true,
        ]);

        $this->assertFalse($cook->hasLogin());
        $this->assertSame('Rashid', $cook->fullName());

        Company::forgetCurrent();
    }
}
