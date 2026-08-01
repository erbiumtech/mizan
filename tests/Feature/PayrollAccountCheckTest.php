<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Setting;
use App\Modules\Payroll\Services\PayrollAccountAudit;
use App\Modules\Payroll\Services\PayrollPostingService;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use RuntimeException;
use Tests\AccountingTestCase;

/**
 * The payroll account-code audit.
 *
 * Production hit "Payroll account 'basic_wage' points at account code 50000,
 * which does not exist" only when a payslip was saved — long after the bad code
 * was entered. This finds the same problem on demand, and repairs it only where
 * falling back to the shipped default is provably safe.
 *
 * Asserted against the service rather than the console command: the command is
 * wrapped in Spatie's TenantAware trait, which iterates real per-tenant database
 * connections and cannot run in this single-database suite.
 */
class PayrollAccountCheckTest extends AccountingTestCase
{
    private function audit(): PayrollAccountAudit
    {
        return app(PayrollAccountAudit::class);
    }

    private function resolveAccountId(string $key): int
    {
        $service = app(PayrollPostingService::class);
        $method = (new ReflectionClass($service))->getMethod('accountId');
        $method->setAccessible(true);

        return $method->invoke($service, $key);
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('payroll:accounts', Artisan::all());
    }

    public function test_nothing_is_broken_when_the_chart_matches_the_defaults(): void
    {
        $this->assertSame([], $this->audit()->broken());

        $report = $this->audit()->report();
        $this->assertCount(count(config(PayrollAccountAudit::SETTING_KEY)), $report);
        $this->assertSame('default', $report[0]['source']);
        $this->assertNotNull($report[0]['account']);
    }

    public function test_an_override_pointing_at_a_missing_code_is_reported(): void
    {
        app(TenantSettings::class)->set(PayrollAccountAudit::SETTING_KEY, ['basic_wage' => '50000']);

        $this->assertSame(['basic_wage' => '50000'], $this->audit()->broken());

        $row = collect($this->audit()->report())->firstWhere('key', 'basic_wage');
        $this->assertSame('override', $row['source']);
        $this->assertNull($row['account']);
    }

    public function test_repair_clears_the_bad_override_and_leaves_good_ones(): void
    {
        app(TenantSettings::class)->set(PayrollAccountAudit::SETTING_KEY, [
            'basic_wage' => '50000',
            'tax_payable' => '2100',
        ]);

        $result = $this->audit()->repair();

        $this->assertArrayHasKey('basic_wage', $result['cleared']);
        $this->assertSame([], $result['unfixable']);

        $stored = (array) Setting::where('key', PayrollAccountAudit::SETTING_KEY)->first()->value;
        $this->assertArrayNotHasKey('basic_wage', $stored);
        $this->assertSame('2100', $stored['tax_payable'], 'a valid override is left alone');

        // The effective value falls back to the shipped default.
        $this->assertSame(
            config(PayrollAccountAudit::SETTING_KEY.'.basic_wage'),
            setting(PayrollAccountAudit::SETTING_KEY)['basic_wage']
        );
        $this->assertSame([], $this->audit()->broken());
    }

    public function test_payroll_posting_resolves_again_after_a_repair(): void
    {
        app(TenantSettings::class)->set(PayrollAccountAudit::SETTING_KEY, ['basic_wage' => '50000']);

        try {
            $this->resolveAccountId('basic_wage');
            $this->fail('expected the missing code to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('50000', $e->getMessage());
        }

        $this->audit()->repair();

        $this->assertIsInt($this->resolveAccountId('basic_wage'));
    }

    public function test_repair_refuses_to_guess_when_the_default_is_missing_too(): void
    {
        // A chart with different numbering: neither the override nor the shipped
        // default exists, so there is nothing safe to fall back to.
        Account::where('code', config(PayrollAccountAudit::SETTING_KEY.'.basic_wage'))->delete();

        app(TenantSettings::class)->set(PayrollAccountAudit::SETTING_KEY, ['basic_wage' => '50000']);

        $result = $this->audit()->repair();

        $this->assertSame([], $result['cleared']);
        $this->assertArrayHasKey('basic_wage', $result['unfixable']);

        $stored = (array) Setting::where('key', PayrollAccountAudit::SETTING_KEY)->first()->value;
        $this->assertSame('50000', $stored['basic_wage'], 'left for a human to correct');
    }

    public function test_a_missing_default_with_no_override_is_reported_as_unfixable(): void
    {
        Account::where('code', config(PayrollAccountAudit::SETTING_KEY.'.esi_payable'))->delete();

        $this->assertArrayHasKey('esi_payable', $this->audit()->broken());
        $this->assertArrayHasKey('esi_payable', $this->audit()->repair()['unfixable']);
    }
}
