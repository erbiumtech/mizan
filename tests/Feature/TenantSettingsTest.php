<?php

namespace Tests\Feature;

use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_config_default_when_no_override(): void
    {
        config()->set('petty_cash.float_amount', 4000);

        $this->assertSame(4000, setting('petty_cash.float_amount'));
    }

    public function test_override_takes_precedence_over_config(): void
    {
        config()->set('petty_cash.float_amount', 4000);

        app(TenantSettings::class)->set('petty_cash.float_amount', 12500.0);

        $this->assertEqualsWithDelta(12500.0, setting('petty_cash.float_amount'), 0.001);
    }

    public function test_array_settings_round_trip(): void
    {
        $codes = ['basic_wage' => '9100', 'tax_payable' => '2999'];
        app(TenantSettings::class)->set('accounting.payroll_accounts', $codes);

        $this->assertSame($codes, setting('accounting.payroll_accounts'));
        $this->assertSame('9100', data_get(setting('accounting.payroll_accounts'), 'basic_wage'));
    }

    public function test_boolean_settings_round_trip(): void
    {
        config()->set('accounting.auto_post_payroll', false);
        $this->assertFalse((bool) setting('accounting.auto_post_payroll'));

        app(TenantSettings::class)->set('accounting.auto_post_payroll', true);
        $this->assertTrue((bool) setting('accounting.auto_post_payroll'));
    }

    public function test_petty_cash_service_reads_the_override(): void
    {
        app(TenantSettings::class)->set('petty_cash.float_amount', 7777.0);

        $service = app(\App\Services\PettyCashService::class);
        $this->assertEqualsWithDelta(7777.0, $service->floatAmount(), 0.001);
    }
}
