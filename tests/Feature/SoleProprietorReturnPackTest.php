<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Filament\Pages\SoleProprietorReturnPack as Page;
use App\Modules\PersonalFinance\Services\SoleProprietorReturnPack;
use Database\Seeders\TaxScheduleSeeder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The sole-proprietor pack: business profit taxed on the individual slabs, not
 * the corporate flat rate — and the routing that sends a sole proprietor here
 * rather than to the Corporate pack. The slab arithmetic itself is
 * PersonalTaxServiceTest's; what is pinned here is that this pack uses it.
 */
class SoleProprietorReturnPackTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxScheduleSeeder::class); // the individual/business slab schedule
    }

    private function book(string $debitCode, string $creditCode, float $amount): void
    {
        $debit = Account::where('code', $debitCode)->firstOrFail();
        $credit = Account::where('code', $creditCode)->firstOrFail();

        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => $this->fiscalYear->start_date->copy()->addMonths(2)->toDateString(), 'memo' => 'test'],
            [
                ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $credit->id, 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => 'approved', 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry);
    }

    public function test_business_profit_is_taxed_on_the_individual_slabs(): void
    {
        $this->book('1100', '4100', 5_000_000); // revenue
        $this->book('5100', '1100', 3_000_000); // expenses → net profit 2,000,000
        $this->book('1260', '1100', 50_000);    // advance tax suffered

        $pack = app(SoleProprietorReturnPack::class)->build($this->fiscalYear->id);

        $this->assertSame(2_000_000.0, $pack['pnl']['net_profit']);
        $this->assertSame(2_000_000.0, $pack['taxable_income']);
        // Non-salaried/individual schedule: 170,000 + 30% of (2,000,000 − 1,600,000).
        $this->assertSame(290_000.0, $pack['tax_due']);
        $this->assertSame(50_000.0, $pack['tax_paid']);
        $this->assertSame(240_000.0, $pack['balance']);
    }

    public function test_adjustments_move_profit_to_taxable_income(): void
    {
        $this->book('1100', '4100', 5_000_000);
        $this->book('5100', '1100', 3_500_000); // net profit 1,500,000

        // Add back 500,000 → taxable 2,000,000 → same 290,000.
        $pack = app(SoleProprietorReturnPack::class)->build($this->fiscalYear->id, [
            'adjustments' => [['label' => 'Depreciation', 'amount' => 500_000]],
        ]);

        $this->assertSame(1_500_000.0, $pack['pnl']['net_profit']);
        $this->assertSame(2_000_000.0, $pack['taxable_income']);
        $this->assertSame(290_000.0, $pack['tax_due']);
    }

    public function test_a_sole_proprietor_is_routed_here_and_away_from_the_corporate_pack(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $company = Company::factory()->create(['type' => Company::TYPE_BUSINESS, 'legal_entity' => Company::LEGAL_SOLE_PROPRIETOR]);
        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $this->actingAs($user);
        $this->setCurrentTenant($company);

        // The sole proprietor sees this pack, not the corporate one.
        $this->assertTrue(Page::canAccess());
        $this->assertFalse(\App\Modules\Accounting\Filament\Pages\CorporateReturnPack::canAccess());
    }

    public function test_an_ordinary_company_sees_the_corporate_pack_not_this_one(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $company = Company::factory()->create(['type' => Company::TYPE_BUSINESS, 'legal_entity' => null]);
        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $this->actingAs($user);
        $this->setCurrentTenant($company);

        $this->assertFalse(Page::canAccess());
        $this->assertTrue(\App\Modules\Accounting\Filament\Pages\CorporateReturnPack::canAccess());
    }
}
