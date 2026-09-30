<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Filament\Pages\ReturnPack;
use App\Modules\PersonalFinance\Services\PersonalReturnPack;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PersonalChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxScheduleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The personal return pack, in IRIS's own shape: the four-column income heads,
 * the 9000-series computations, the coded wealth statement and its 703-series
 * reconciliation — modelled on a real 114(1) print. The bracket arithmetic is
 * pinned by PersonalTaxServiceTest; what is asserted here is the assembly, and
 * above all the final/normal split: final-regime income must never join taxable
 * income (9100), and its tax must land in Fixed/Final (920100).
 */
class PersonalReturnPackTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create(['type' => Company::TYPE_PERSONAL]);
        $this->seed([PermissionSeeder::class, FiscalYearSeeder::class]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['status' => 1]);
        $company->users()->syncWithoutDetaching([$user->getKey()]);
        $this->actingAs($user);
        $user->assignRole('Administrator');
        $this->setCurrentTenant($company);

        $this->seed([PersonalChartOfAccountsSeeder::class, TaxScheduleSeeder::class]);

        $this->year = FiscalYear::where('name', '2025-2026')->firstOrFail();
    }

    private function pack(): array
    {
        return app(PersonalReturnPack::class)->build($this->year->id);
    }

    /** One coded row out of a coded list — the shape every IRIS block shares. */
    private function line(array $rows, string $code): array
    {
        return collect($rows)->firstWhere('code', $code) ?? $this->fail("no row with code {$code}");
    }

    /** A posted entry moving $amount from $creditCode to $debitCode inside the year. */
    private function book(string $debitCode, string $creditCode, float $amount, string $date = '2025-09-15'): void
    {
        $debit = Account::where('code', $debitCode)->firstOrFail();
        $credit = Account::where('code', $creditCode)->firstOrFail();

        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => $date, 'memo' => "{$debitCode} <- {$creditCode}"],
            [
                ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $credit->id, 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => 'approved', 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry);
    }

    /**
     * The shape of the return this was modelled on: a small salary under the
     * exempt threshold beside a large export-of-services income. IRIS taxes the
     * export at a flat final 1% and keeps it out of taxable income entirely —
     * the return's own draft shows taxable income (9100) as the salary alone.
     */
    public function test_final_regime_income_stays_out_of_taxable_income(): void
    {
        $this->book('1100', '4000', 390_500);      // salary — below the threshold, slabs owe nothing
        $this->book('1100', '4500', 14_176_283);   // export of services — final, 1%

        $pack = $this->pack();

        $this->assertSame(14_566_783.0, $this->line($pack['computations'], '9000')['amount']);
        $this->assertSame(390_500.0, $this->line($pack['computations'], '9100')['amount']);
        $this->assertSame(141_762.83, $this->line($pack['computations'], '920100')['amount']);
        $this->assertSame(141_762.83, $this->line($pack['computations'], '9200')['amount']);

        // The heads print the way the return prints them: export inside head 3000's
        // final column, salary inside head 1000's normal column.
        $business = $this->line($pack['heads'], '3000');
        $this->assertSame(14_176_283.0, $business['final']);
        $this->assertSame(0.0, $business['normal']);
        $this->assertSame(390_500.0, $this->line($pack['heads'], '1000')['normal']);
    }

    public function test_withholding_nets_against_the_chargeable_tax(): void
    {
        $this->book('1100', '4000', 1_000_000); // salary: slabs owe 4,000
        $this->book('1600', '1100', 2_500);     // employer's withholding, recorded as the asset it is

        $pack = $this->pack();

        $this->assertSame(4_000.0, $pack['tax_chargeable']);
        $this->assertSame(2_500.0, $this->line($pack['computations'], '9201')['amount']);
        $this->assertSame('Admitted Income Tax', $this->line($pack['computations'], '9203')['label']);
        $this->assertSame(1_500.0, $pack['balance']);
    }

    public function test_withholding_is_itemised_by_section_and_summed_to_9201(): void
    {
        $this->book('1100', '4000', 3_000_000); // salary
        // Withholding across three sections, recorded to their section accounts.
        $this->book('1601', '1100', 300_000);   // salary, s.149
        $this->book('1602', '1100', 5_000);     // profit on debt, s.151
        $this->book('1603', '1100', 120_000);   // property, s.236C

        $pack = $this->pack();

        // Each section appears once, general (1600) is absent (no movement), and the
        // three sum to the 9201 total.
        $codes = array_column($pack['withholding_by_section'], 'code');
        $this->assertSame(['1601', '1602', '1603'], $codes);
        $this->assertSame(425_000.0, $pack['tax_paid']);
        $this->assertSame(425_000.0, $this->line($pack['computations'], '9201')['amount']);

        // A filer using only the general 1600 still gets a single line and the same total.
        $this->assertStringContainsString('Salary', collect($pack['withholding_by_section'])->firstWhere('code', '1601')['section']);
    }

    public function test_the_wealth_statement_reconciles_and_carries_iris_codes(): void
    {
        $this->book('1100', '4000', 1_000_000); // income
        $this->book('5100', '1100', 200_000);   // groceries
        $this->book('1600', '1100', 2_500);     // withholding — an asset swap, not consumption

        $pack = $this->pack();

        $this->assertSame(0.0, $pack['wealth']['opening_net']);
        // 797,500 in the bank + 2,500 claimable withholding.
        $this->assertSame(800_000.0, $pack['wealth']['closing_net']);

        // Each row beside the IRIS 7000-code it declares under.
        $rows = collect($pack['wealth']['assets']);
        $this->assertSame('7006', $rows->firstWhere('code', '1100')['iris_code']);
        $this->assertSame('7015', $rows->firstWhere('code', '1600')['iris_code']);

        $this->assertSame(1_000_000.0, $this->line($pack['reconciliation'], '7049')['amount']);
        $this->assertSame(200_000.0, $this->line($pack['reconciliation'], '7089')['amount']);
        $this->assertSame(0.0, $this->line($pack['reconciliation'], '703000')['amount']);
    }

    public function test_wealth_arriving_outside_income_shows_as_unreconciled(): void
    {
        $this->book('1100', '4000', 1_000_000);
        // Money into the bank against equity — a gift, or an opening balance set
        // mid-year. Exactly what a return gets asked about, so exactly what the
        // reconciliation must refuse to absorb.
        $this->book('1100', '3300', 50_000);

        $this->assertSame(50_000.0, $this->line($this->pack()['reconciliation'], '703000')['amount']);
        $this->assertSame(50_000.0, $this->pack()['unreconciled']);
    }

    public function test_the_page_renders_on_a_personal_account(): void
    {
        $this->book('1100', '4000', 1_000_000);

        Livewire::test(ReturnPack::class)
            ->assertOk()
            ->assertSee('Admitted income tax');
    }

    public function test_the_page_is_refused_on_a_business_account(): void
    {
        $business = Company::factory()->create(['type' => Company::TYPE_BUSINESS]);
        $this->setCurrentTenant($business);

        $this->assertFalse(ReturnPack::canAccess());
    }
}
