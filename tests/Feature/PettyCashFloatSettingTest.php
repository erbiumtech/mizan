<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\PettyCashService;
use App\Modules\Core\Filament\Pages\CompanySettings;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The petty cash float is Accounting's own setting on Company Settings → Money.
 *
 * It was a field Core's page carried for Accounting, and a required one: a company that keeps no petty cash
 * could not save any setting without inventing a float. Blank now means "no float"; a company that has never
 * answered still gets the installation default (PETTY_CASH_FLOAT).
 */
class PettyCashFloatSettingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'pettycash@test.local'));
        $this->setCurrentTenant();

        config(['petty_cash.float_amount' => 4000.0]);
    }

    public function test_a_company_that_never_answered_gets_the_installation_default(): void
    {
        $this->assertSame(4000.0, app(PettyCashService::class)->floatAmount());

        Livewire::test(CompanySettings::class)->assertSet('data.petty_cash_float_amount', 4000.0);
    }

    public function test_the_float_is_saved_from_company_settings(): void
    {
        Livewire::test(CompanySettings::class)
            ->set('data.petty_cash_float_amount', 25000)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(25000.0, app(PettyCashService::class)->floatAmount());
    }

    /** Blank is an answer — no float — and not a way back to the installation default. */
    public function test_a_blank_float_saves_and_means_no_float(): void
    {
        Livewire::test(CompanySettings::class)
            ->set('data.petty_cash_float_amount', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(setting('petty_cash.float_amount'));
        $this->assertSame(0.0, app(PettyCashService::class)->floatAmount());

        Livewire::test(CompanySettings::class)->assertSet('data.petty_cash_float_amount', null);
    }
}
