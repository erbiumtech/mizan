<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Payroll\Filament\Settings\PayrollRunSettingsSection;
use App\Modules\Payroll\Services\StatutoryContributions;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The provident fund is a company's choice, not the server's.
 *
 * `STATUTORY_PF_ENABLED` switched it on or off for every company on an installation. Each company now decides
 * under Company Settings → Payroll; a company that has not said keeps the installation's default, which is off.
 */
class ProvidentFundSettingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'pf-setting@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        config(['statutory.provident_fund.enabled' => false, 'statutory.provident_fund.employee_rate' => 0.0833, 'statutory.provident_fund.employer_rate' => 0.0833]);
    }

    private function statutory(): StatutoryContributions
    {
        app(TenantSettings::class)->flush();

        return app(StatutoryContributions::class);
    }

    public function test_a_company_that_has_not_said_keeps_the_installations_default_off(): void
    {
        $this->assertFalse($this->statutory()->providentFundEnabled());
        $this->assertSame(0.0, $this->statutory()->providentFund(100000)['provident_fund_employee']);
    }

    public function test_a_company_can_switch_it_on_at_its_own_rates(): void
    {
        (new PayrollRunSettingsSection)->save([
            'payroll_require_approval' => false,
            'payroll_provident_fund_enabled' => true,
            'payroll_provident_fund_employee_rate' => 10,
            'payroll_provident_fund_employer_rate' => 5,
        ]);

        $pf = $this->statutory()->providentFund(100000);

        $this->assertTrue($this->statutory()->providentFundEnabled());
        $this->assertSame(10000.0, $pf['provident_fund_employee']);
        $this->assertSame(5000.0, $pf['provident_fund_employer']);
    }

    public function test_a_company_can_switch_it_off_where_the_installation_has_it_on(): void
    {
        config(['statutory.provident_fund.enabled' => true]);
        app(TenantSettings::class)->set(StatutoryContributions::PROVIDENT_FUND_ENABLED, false);

        $this->assertFalse($this->statutory()->providentFundEnabled());
    }

    public function test_the_settings_section_shows_the_rates_as_percentages(): void
    {
        $state = (new PayrollRunSettingsSection)->fill();

        $this->assertFalse($state['payroll_provident_fund_enabled']);
        $this->assertSame(8.33, $state['payroll_provident_fund_employee_rate']);
    }
}
