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
 * The personal return pack: the 114(1) figures, tax already paid, and the wealth
 * statement's reconciliation — over the tenant's real ledger, like the estimate
 * it builds on. The salary arithmetic is pinned by PersonalTaxServiceTest; what
 * is asserted here is the assembly around it.
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

    public function test_the_pack_nets_withheld_tax_against_the_liability(): void
    {
        $this->book('1100', '4000', 1_000_000); // salary into the bank
        $this->book('1600', '1100', 2_500);     // employer's withholding, recorded as the asset it is

        $pack = $this->pack();

        // 1% of (1,000,000 - 600,000) — the same figure PersonalTaxServiceTest pins.
        $this->assertSame(4000.0, $pack['income']['total_payable']);
        $this->assertSame(2500.0, $pack['tax_paid']);
        $this->assertSame(1500.0, $pack['balance']);
    }

    public function test_the_wealth_statement_reconciles_when_every_flow_is_booked(): void
    {
        $this->book('1100', '4000', 1_000_000); // income
        $this->book('5100', '1100', 200_000);   // groceries
        $this->book('1600', '1100', 2_500);     // withholding — an asset swap, not consumption

        $pack = $this->pack();

        $this->assertSame(0.0, $pack['wealth']['opening_net']);
        // 797,500 in the bank + 2,500 claimable withholding.
        $this->assertSame(800_000.0, $pack['wealth']['closing_net']);
        $this->assertSame(1_000_000.0, $pack['reconciliation']['inflows']);
        $this->assertSame(200_000.0, $pack['reconciliation']['expenses']);
        $this->assertSame(0.0, $pack['reconciliation']['unexplained']);
    }

    public function test_wealth_arriving_outside_income_shows_as_unexplained(): void
    {
        $this->book('1100', '4000', 1_000_000);
        // Money into the bank against equity — a gift, or an opening balance set
        // mid-year. Exactly what a return gets asked about, so exactly what the
        // reconciliation must refuse to absorb.
        $this->book('1100', '3300', 50_000);

        $this->assertSame(50_000.0, $this->pack()['reconciliation']['unexplained']);
    }

    public function test_the_page_renders_on_a_personal_account_and_downloads_nothing_broken(): void
    {
        $this->book('1100', '4000', 1_000_000);

        Livewire::test(ReturnPack::class)
            ->assertOk()
            ->assertSee('Payable with the return');
    }

    public function test_the_page_is_refused_on_a_business_account(): void
    {
        $business = Company::factory()->create(['type' => Company::TYPE_BUSINESS]);
        $this->setCurrentTenant($business);

        $this->assertFalse(ReturnPack::canAccess());
    }
}
