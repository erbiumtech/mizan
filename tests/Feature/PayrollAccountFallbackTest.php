<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Setting;
use App\Services\PayrollPostingService;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;

/**
 * Production hit "Payroll account 'basic_wage' (code 0) not found".
 *
 * Company Settings writes the whole payroll-account map in one go, and
 * TenantSettings used to return an override wholesale — so a partial or blank
 * save erased every key it did not carry. Because that KeyValue field is not
 * addable, the missing lines could not even be restored from the page: payroll
 * posting was wedged with no way out through the UI.
 *
 * Map-shaped settings are now merged over their config defaults, so the shipped
 * values are always the floor.
 */
class PayrollAccountFallbackTest extends AccountingTestCase
{
    private function override(mixed $value): void
    {
        Setting::updateOrCreate(['key' => 'accounting.payroll_accounts'], ['value' => $value]);
        app(TenantSettings::class)->flush();
    }

    private function codes(): array
    {
        return setting('accounting.payroll_accounts');
    }

    /** accountId() is protected; the service is what production calls. */
    private function resolveAccountId(string $key): int
    {
        $service = app(PayrollPostingService::class);
        $method = new \ReflectionMethod($service, 'accountId');
        $method->setAccessible(true);

        return $method->invoke($service, $key);
    }

    public function test_a_partial_override_keeps_the_other_defaults(): void
    {
        $this->override(['basic_wage' => '5100']);

        $codes = $this->codes();

        $this->assertSame('5100', $codes['basic_wage']);
        $this->assertSame('2300', $codes['salaries_payable'], 'the untouched keys must survive');
        $this->assertSame('2100', $codes['tax_payable']);
    }

    public function test_an_empty_override_falls_back_entirely(): void
    {
        $this->override([]);

        $this->assertSame(
            config('accounting.payroll_accounts'),
            $this->codes(),
            'a blank save must not erase the defaults'
        );
    }

    public function test_a_blank_value_falls_back_for_that_key(): void
    {
        $this->override(['basic_wage' => '', 'salaries_payable' => '2300']);

        $this->assertSame('5100', $this->codes()['basic_wage'], 'blank reads as "use the default"');
    }

    public function test_a_real_override_still_wins(): void
    {
        $this->override(['basic_wage' => '5110']);

        $this->assertSame('5110', $this->codes()['basic_wage']);
    }

    /** Non-map settings must behave exactly as before. */
    public function test_scalar_overrides_are_untouched(): void
    {
        Setting::updateOrCreate(['key' => 'accounting.auto_post_payroll'], ['value' => true]);
        app(TenantSettings::class)->flush();

        $this->assertTrue(setting('accounting.auto_post_payroll'));
    }

    public function test_a_list_override_replaces_rather_than_merges(): void
    {
        config(['testing.list_setting' => ['a', 'b', 'c']]);
        Setting::updateOrCreate(['key' => 'testing.list_setting'], ['value' => ['x']]);
        app(TenantSettings::class)->flush();

        $this->assertSame(['x'], setting('testing.list_setting'), 'a list is not a map; replace it');
    }

    // --- the failure as production saw it -----------------------------------

    /** The exact wedge: a zero code where a real account code belongs. */
    public function test_payroll_posts_even_when_a_code_was_saved_as_zero(): void
    {
        $this->override(['basic_wage' => 0]);

        $this->assertSame(
            Account::where('code', '5100')->value('id'),
            $this->resolveAccountId('basic_wage'),
            'a zero code should fall back to the shipped default, not throw'
        );
    }

    public function test_a_code_that_is_not_in_the_chart_reports_where_to_fix_it(): void
    {
        $this->override(['basic_wage' => '9999']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/9999.*does not exist.*Company Settings/s');
        $this->resolveAccountId('basic_wage');
    }
}
