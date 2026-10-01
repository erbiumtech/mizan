<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\CorporateReturnPack;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\Company;
use Tests\AccountingTestCase;

/**
 * The corporate return pack: ledger profit, the hand-kept tax adjustments, and
 * the s.113 minimum-tax comparison — the arithmetic IRIS runs, pinned here.
 */
class CorporateReturnPackTest extends AccountingTestCase
{
    use \Tests\Concerns\InteractsWithTenant;

    /** A posted entry moving $amount from $creditCode to $debitCode inside the year. */
    private function book(string $debitCode, string $creditCode, float $amount): void
    {
        $debit = Account::where('code', $debitCode)->firstOrFail();
        $credit = Account::where('code', $creditCode)->firstOrFail();

        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-09-15', 'memo' => "{$debitCode} <- {$creditCode}"],
            [
                ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $credit->id, 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => 'approved', 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry);
    }

    private function pack(?array $worksheet = null): array
    {
        return app(CorporateReturnPack::class)->build($this->fiscalYear->id, $worksheet);
    }

    public function test_adjustments_move_accounting_profit_to_taxable_income(): void
    {
        $this->book('1100', '4100', 10_000_000); // service revenue into the bank
        $this->book('5100', '1100', 4_000_000);  // expenses out of it

        $pack = $this->pack([
            'tax_rate' => 29,
            'minimum_tax_rate' => 1.25,
            'adjustments' => [
                ['label' => 'Accounting depreciation added back', 'amount' => 500_000],
                ['label' => 'Tax depreciation (Third Schedule)', 'amount' => -200_000],
            ],
        ]);

        $this->assertSame(6_000_000.0, $pack['pnl']['net_profit']);
        $this->assertSame(300_000.0, $pack['adjustments_total']);
        $this->assertSame(6_300_000.0, $pack['taxable_income']);
        $this->assertSame(1_827_000.0, $pack['normal_tax']);   // 29% of 6.3m
        $this->assertSame(125_000.0, $pack['minimum_tax']);    // 1.25% of 10m turnover
        $this->assertSame('normal', $pack['basis']);
        $this->assertSame(1_827_000.0, $pack['tax_due']);
    }

    public function test_minimum_tax_wins_in_a_loss_year(): void
    {
        $this->book('1100', '4100', 10_000_000);
        $this->book('5100', '1100', 12_000_000); // a loss — normal tax is nought

        $pack = $this->pack(['tax_rate' => 29, 'minimum_tax_rate' => 1.25, 'adjustments' => []]);

        $this->assertSame(0.0, $pack['normal_tax']);
        $this->assertSame('minimum', $pack['basis']);
        $this->assertSame(125_000.0, $pack['tax_due']);
    }

    public function test_advance_tax_suffered_nets_against_the_liability(): void
    {
        $this->book('1100', '4100', 10_000_000);
        $this->book('1260', '1100', 90_000); // customer withheld on a receipt

        $pack = $this->pack(['tax_rate' => 29, 'minimum_tax_rate' => 1.25, 'adjustments' => []]);

        $this->assertSame(90_000.0, $pack['tax_paid']);
        $this->assertSame(round($pack['tax_due'] - 90_000, 2), $pack['balance']);
    }

    public function test_a_brought_forward_loss_reduces_taxable_income_but_not_below_zero(): void
    {
        $this->book('1100', '4100', 10_000_000);
        $this->book('5100', '1100', 4_000_000); // accounting profit 6,000,000

        // A 2m brought-forward loss: taxable income drops to 4m, normal tax 29% of 4m.
        $pack = $this->pack(['tax_rate' => 29, 'minimum_tax_rate' => 1.25, 'brought_forward_loss' => 2_000_000]);
        $this->assertSame(2_000_000.0, $pack['loss_applied']);
        $this->assertSame(4_000_000.0, $pack['taxable_income']);
        $this->assertSame(1_160_000.0, $pack['normal_tax']);

        // A loss larger than the income absorbs only what there is — taxable income floors at zero,
        // and minimum tax then wins.
        $pack = $this->pack(['tax_rate' => 29, 'minimum_tax_rate' => 1.25, 'brought_forward_loss' => 9_000_000]);
        $this->assertSame(6_000_000.0, $pack['loss_applied']);
        $this->assertSame(0.0, $pack['taxable_income']);
        $this->assertSame(0.0, $pack['normal_tax']);
        $this->assertSame('minimum', $pack['basis']);
    }

    public function test_a_small_company_opens_the_pack_at_the_reduced_rate(): void
    {
        $this->book('1100', '4100', 10_000_000);
        $this->book('5100', '1100', 4_000_000); // accounting profit 6,000,000

        // A current company for defaultTaxRate() to read — the ledger stays on the
        // shared connection, so marking this one small changes only the rate.
        $user = \App\Modules\Core\Models\User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        $company = $this->setCurrentTenant();

        // An ordinary company (the default) opens at 29%.
        $this->assertSame(29.0, app(CorporateReturnPack::class)->worksheet($this->fiscalYear->id)['tax_rate']);
        $this->assertSame(1_740_000.0, $this->pack()['normal_tax']); // 29% of 6m

        // Mark it a small company — the default rate drops to 20%, and a worksheet
        // with no saved rate computes at it.
        $company->update(['legal_entity' => \App\Modules\Core\Models\Company::LEGAL_SMALL_COMPANY]);
        \Filament\Facades\Filament::setTenant($company->fresh());

        $this->assertTrue($company->fresh()->isSmallCompany());
        $this->assertSame(20.0, app(CorporateReturnPack::class)->worksheet($this->fiscalYear->id)['tax_rate']);
        $this->assertSame(1_200_000.0, $this->pack()['normal_tax']); // 20% of 6m

        // Still only a DEFAULT: a saved rate wins over the entity's default.
        $this->assertSame(25.0, app(CorporateReturnPack::class)->build($this->fiscalYear->id, ['tax_rate' => 25])['worksheet']['tax_rate']);
    }

    public function test_an_llp_is_taxed_at_the_full_company_rate_not_the_small_one(): void
    {
        $this->book('1100', '4100', 10_000_000);
        $this->book('5100', '1100', 4_000_000); // accounting profit 6,000,000

        $user = \App\Modules\Core\Models\User::factory()->create(['status' => 1]);
        $this->actingAs($user);
        $company = $this->setCurrentTenant();

        $company->update(['legal_entity' => \App\Modules\Core\Models\Company::LEGAL_LLP]);
        \Filament\Facades\Filament::setTenant($company->fresh());

        // A body corporate taxed as a company: the ordinary 29% default, not the
        // small-company 20%. The operator still asserts small-company status
        // separately if the LLP qualifies, by editing the rate.
        $this->assertTrue($company->fresh()->isLlp());
        $this->assertFalse($company->fresh()->isSmallCompany());
        $this->assertSame(29.0, app(CorporateReturnPack::class)->worksheet($this->fiscalYear->id)['tax_rate']);
        $this->assertSame(1_740_000.0, $this->pack()['normal_tax']); // 29% of 6m
    }

    public function test_a_non_profit_records_its_approval_and_computes_ordinary_figures_for_now(): void
    {
        $company = \App\Modules\Core\Models\Company::factory()->create([
            'type' => 'business',
            'legal_entity' => \App\Modules\Core\Models\Company::LEGAL_NON_PROFIT,
            'tax_exempt_ref' => 'NPO-2024-001',
            'tax_exempt_approved_on' => '2024-07-01',
        ]);

        // The approval round-trips and is typed.
        $fresh = $company->fresh();
        $this->assertTrue($fresh->isNonProfit());
        $this->assertSame('NPO-2024-001', $fresh->tax_exempt_ref);
        $this->assertSame('2024-07-01', $fresh->tax_exempt_approved_on->toDateString());

        // Scaffolding only: the s.100C credit and s.113 carve-out are not applied,
        // so the pack still computes the ordinary-company position. The pack's
        // notice is what tells the reader those figures are not the NPO's tax.
        $this->assertFalse($fresh->isSmallCompany());
    }

    public function test_legal_entity_falls_back_to_the_type_when_unset(): void
    {
        $business = \App\Modules\Core\Models\Company::factory()->create(['type' => 'business', 'legal_entity' => null]);
        $personal = \App\Modules\Core\Models\Company::factory()->create(['type' => 'personal', 'legal_entity' => null]);

        // Null reads as the entity the type implies — today's behaviour, unchanged.
        $this->assertSame('company', $business->legalEntity());
        $this->assertSame('individual', $personal->legalEntity());
        $this->assertFalse($business->isSmallCompany());
    }

    public function test_super_tax_adds_on_top_of_the_greater_base(): void
    {
        $this->book('1100', '4100', 10_000_000);
        $this->book('5100', '1100', 4_000_000);

        $pack = $this->pack(['tax_rate' => 29, 'minimum_tax_rate' => 1.25, 'super_tax' => 150_000]);

        // 29% of 6m = 1,740,000 base, plus 150,000 super tax on top.
        $this->assertSame(1_740_000.0, $pack['normal_tax']);
        $this->assertSame(150_000.0, $pack['super_tax']);
        $this->assertSame(1_890_000.0, $pack['tax_due']);
    }

    public function test_the_worksheet_survives_a_round_trip_and_drops_empty_rows(): void
    {
        $service = app(CorporateReturnPack::class);

        $service->saveWorksheet($this->fiscalYear->id, [
            'tax_rate' => 20, // a small company's rate — the reason it is editable
            'minimum_tax_rate' => 1.25,
            'adjustments' => [
                ['label' => 'Inadmissible fines', 'amount' => 15_000],
                ['label' => '', 'amount' => 0], // a click, not an adjustment
            ],
        ]);

        $worksheet = $service->worksheet($this->fiscalYear->id);

        $this->assertSame(20.0, $worksheet['tax_rate']);
        $this->assertSame([['label' => 'Inadmissible fines', 'amount' => 15_000.0]], $worksheet['adjustments']);

        // And build() with no override reads the stored one.
        $this->assertSame(15_000.0, $this->pack()['adjustments_total']);
    }

    public function test_the_save_action_persists_what_the_form_shows(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $company = Company::factory()->create();
        $user = \App\Modules\Core\Models\User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $this->actingAs($user);
        $this->setCurrentTenant($company);

        // fillForm rather than raw ->set(): it routes through Filament's own field
        // and repeater hydration, so the state reaching the action is shaped the
        // way a real browser shapes it — the raw form of this test passed while
        // the real page failed to persist, which is exactly the gap.
        \Livewire\Livewire::test(\App\Modules\Accounting\Filament\Pages\CorporateReturnPack::class)
            ->fillForm([
                'fiscal_year_id' => $this->fiscalYear->id,
                'tax_rate' => 20,
                'adjustments' => [
                    ['label' => 'Inadmissible fines', 'amount' => '15000'],
                ],
            ])
            ->callAction('save')
            ->assertNotified();

        $worksheet = app(CorporateReturnPack::class)->worksheet($this->fiscalYear->id);

        $this->assertSame(20.0, $worksheet['tax_rate']);
        $this->assertSame([['label' => 'Inadmissible fines', 'amount' => 15_000.0]], $worksheet['adjustments']);
    }

    public function test_the_page_is_for_business_accounts_only(): void
    {
        $personal = Company::factory()->create(['type' => Company::TYPE_PERSONAL]);
        // The container instance rather than makeCurrent(): the single-database
        // suite has no tenant connection to switch — same idiom as TableViewTest.
        app()->instance('currentTenant', $personal);

        try {
            $this->assertFalse(\App\Modules\Accounting\Filament\Pages\CorporateReturnPack::canAccess());
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }
}
