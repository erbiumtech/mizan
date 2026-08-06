<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Services\PersonalTaxService;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PersonalChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxScheduleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The individual tax estimate, computed over the tenant's real ledger.
 *
 * The salaried expectations are the same arithmetic TaxCalculatorTest pins for
 * payroll, deliberately: the two calculators are separate code over separate
 * tables, and if they ever disagreed on the same schedule one would be wrong.
 */
class PersonalTaxServiceTest extends TestCase
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

    private function tax(): PersonalTaxService
    {
        return app(PersonalTaxService::class);
    }

    /** Book income against a category, posted, in the 2025-2026 year. */
    private function earn(string $incomeCode, float $amount): void
    {
        $bank = Account::where('code', '1100')->firstOrFail();
        $income = Account::where('code', $incomeCode)->firstOrFail();

        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2025-09-15', 'memo' => 'Income'],
            [
                ['account_id' => $bank->id, 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $income->id, 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => 'approved', 'approved_at' => now()]);
        app(JournalEntryService::class)->post($entry);
    }

    public function test_salaried_brackets_match_the_payroll_calculator(): void
    {
        $cases = [
            500000 => 0.0,
            1000000 => 4000.0,     // 1% of (1,000,000 - 600,000)
            2000000 => 94000.0,    // 6,000 + 11% of 800,000
            3000000 => 300000.0,   // 116,000 + 23% of 800,000
            10000000 => 2681000.0, // 616,000 + 35% of 5,900,000
        ];

        foreach ($cases as $income => $expected) {
            $result = $this->tax()->taxFor($income, TaxSchedule::REGIME_SALARIED, $this->year->id);

            $this->assertSame($expected, $result['tax'], "salaried tax on {$income}");
        }
    }

    public function test_the_breakdown_shows_its_working(): void
    {
        $result = $this->tax()->taxFor(3000000, TaxSchedule::REGIME_SALARIED, $this->year->id);

        $this->assertSame(300000.0, $result['tax']);
        $this->assertSame(23.0, $result['marginal_rate']);
        $this->assertSame(10.0, $result['effective_rate']);
        $this->assertNotNull($result['bracket']);
    }

    public function test_business_income_uses_its_own_schedule(): void
    {
        $income = 2000000.0;

        $salaried = $this->tax()->taxFor($income, TaxSchedule::REGIME_SALARIED, $this->year->id);
        $business = $this->tax()->taxFor($income, TaxSchedule::REGIME_BUSINESS, $this->year->id);

        $this->assertSame(290000.0, $business['tax']);
        $this->assertNotSame($salaried['tax'], $business['tax']);
    }

    public function test_income_above_the_top_bracket_is_taxed_not_ignored(): void
    {
        $result = $this->tax()->taxFor(500000000, TaxSchedule::REGIME_SALARIED, $this->year->id);

        $this->assertGreaterThan(0.0, $result['tax']);
        $this->assertSame(35.0, $result['marginal_rate']);
    }

    public function test_a_missing_schedule_raises_rather_than_returning_zero(): void
    {
        $other = FiscalYear::where('name', '2026-2027')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Salaried tax bracket covers');

        $this->tax()->taxFor(3000000, TaxSchedule::REGIME_SALARIED, $other->id);
    }

    public function test_income_is_grouped_by_the_regime_on_its_account(): void
    {
        // The starter chart already tags Salary as salaried and Rental as rental.
        $this->earn('4000', 1500000);
        $this->earn('4200', 400000);

        $income = $this->tax()->incomeByRegime($this->year->id);

        $this->assertSame(1500000.0, $income['by_regime'][TaxSchedule::REGIME_SALARIED]);
        $this->assertSame(400000.0, $income['by_regime'][TaxSchedule::REGIME_RENTAL]);
    }

    public function test_untagged_income_is_reported_not_silently_taxed_as_salary(): void
    {
        // 4900 Other Income ships with no regime, on purpose.
        $this->earn('4900', 250000);

        $estimate = $this->tax()->estimate($this->year->id);

        $this->assertSame(250000.0, $estimate['unclassified']);
        $this->assertSame(0.0, $estimate['total_tax']);
    }

    public function test_the_estimate_totals_every_regime(): void
    {
        $this->earn('4000', 3000000);

        $estimate = $this->tax()->estimate($this->year->id);

        $this->assertSame(3000000.0, $estimate['total_income']);
        $this->assertSame(300000.0, $estimate['total_tax']);
        $this->assertSame('Salaried', $estimate['regimes'][0]['label']);
    }

    public function test_unposted_income_is_not_taxed(): void
    {
        $bank = Account::where('code', '1100')->firstOrFail();
        $salary = Account::where('code', '4000')->firstOrFail();

        // Created but never posted. Every other report in the app ignores
        // unposted entries, and taxing one would be taxing an intention.
        app(JournalEntryService::class)->create(
            ['entry_date' => '2025-09-15', 'memo' => 'Draft'],
            [
                ['account_id' => $bank->id, 'debit_amount' => 900000, 'credit_amount' => 0],
                ['account_id' => $salary->id, 'debit_amount' => 0, 'credit_amount' => 900000],
            ],
        );

        $this->assertSame(0.0, $this->tax()->estimate($this->year->id)['total_income']);
    }

    public function test_every_seeded_schedule_has_an_unbounded_top_bracket(): void
    {
        $bounded = [];

        foreach (array_keys(TaxSchedule::REGIMES) as $regime) {
            $top = TaxSchedule::where('fiscal_year_id', $this->year->id)
                ->where('regime', $regime)
                ->orderByDesc('min_amount')
                ->first();

            if ($top !== null && $top->max_amount !== null) {
                $bounded[] = $regime;
            }
        }

        $this->assertSame([], $bounded, 'Bounded top bracket: '.implode(', ', $bounded));
    }
}
