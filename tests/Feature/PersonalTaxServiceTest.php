<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Services\PersonalEntryService;
use App\Modules\PersonalFinance\Services\PersonalTaxService;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxScheduleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The tax estimate.
 *
 * The salaried expectations are the same arithmetic TaxCalculatorTest pins for
 * payroll, deliberately: the two calculators are separate code over separate
 * tables, and if they ever disagreed on the same schedule one of them would be
 * wrong.
 */
class PersonalTaxServiceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->seed([PermissionSeeder::class, FiscalYearSeeder::class]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $user->assignRole('Employee');

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        $this->seed(TaxScheduleSeeder::class);

        $this->year = FiscalYear::where('name', '2025-2026')->firstOrFail();
    }

    private function tax(): PersonalTaxService
    {
        return app(PersonalTaxService::class);
    }

    public function test_salaried_brackets_match_the_payroll_calculator(): void
    {
        // Same expectations as TaxCalculatorTest for 2025-2026.
        $cases = [
            500000 => 0.0,
            600000 => 0.0,
            1000000 => 4000.0,     // 1% of (1,000,000 - 600,000)
            1200000 => 6000.0,
            2000000 => 94000.0,    // 6,000 + 11% of 800,000
            3000000 => 300000.0,   // 116,000 + 23% of 800,000
            4000000 => 586000.0,   // 346,000 + 30% of 800,000
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

        // A bare number is not enough on a screen telling somebody what they owe.
        $this->assertSame(300000.0, $result['tax']);
        $this->assertSame(23.0, $result['marginal_rate']);
        $this->assertSame(10.0, $result['effective_rate']); // 300,000 / 3,000,000
        $this->assertNotNull($result['bracket']);
        $this->assertSame('2200000.00', (string) $result['bracket']->min_amount);
    }

    public function test_business_income_uses_its_own_schedule(): void
    {
        $income = 2000000.0;

        $salaried = $this->tax()->taxFor($income, TaxSchedule::REGIME_SALARIED, $this->year->id);
        $business = $this->tax()->taxFor($income, TaxSchedule::REGIME_BUSINESS, $this->year->id);

        // 170,000 + 30% of (2,000,000 - 1,600,000)
        $this->assertSame(290000.0, $business['tax']);

        // The whole reason for a regime column: the same income is taxed
        // differently depending on how it was earned.
        $this->assertNotSame($salaried['tax'], $business['tax']);
    }

    public function test_income_above_the_top_bracket_is_taxed_not_ignored(): void
    {
        // Payroll's failure mode was a bounded top bracket silently producing
        // zero. Here the top bracket is unbounded and a very large income is
        // still taxed.
        $result = $this->tax()->taxFor(500000000, TaxSchedule::REGIME_SALARIED, $this->year->id);

        $this->assertGreaterThan(0.0, $result['tax']);
        $this->assertSame(35.0, $result['marginal_rate']);
    }

    public function test_a_missing_schedule_raises_rather_than_returning_zero(): void
    {
        $other = FiscalYear::where('name', '2026-2027')->firstOrFail();

        // No schedule is seeded for that year. Silently answering "you owe
        // nothing" is exactly the bug this module was built to avoid.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Salaried tax bracket covers');

        $this->tax()->taxFor(3000000, TaxSchedule::REGIME_SALARIED, $other->id);
    }

    public function test_zero_income_is_free_and_does_not_raise(): void
    {
        $result = $this->tax()->taxFor(0, TaxSchedule::REGIME_SALARIED, $this->year->id);

        $this->assertSame(0.0, $result['tax']);
        $this->assertNull($result['bracket']);
    }

    public function test_income_is_grouped_by_the_regime_on_its_account(): void
    {
        $bank = PersonalAccount::create([
            'code' => '1100', 'name' => 'Bank', 'type' => PersonalAccount::TYPE_ASSET,
        ]);
        $salary = PersonalAccount::create([
            'code' => '4000', 'name' => 'Salary', 'type' => PersonalAccount::TYPE_INCOME,
            'tax_regime' => TaxSchedule::REGIME_SALARIED,
        ]);
        $rent = PersonalAccount::create([
            'code' => '4100', 'name' => 'Shop rent', 'type' => PersonalAccount::TYPE_INCOME,
            'tax_regime' => TaxSchedule::REGIME_RENTAL,
        ]);

        $entries = app(PersonalEntryService::class);
        $entries->recordIncome($bank, $salary, 1500000, ['date' => '2025-09-01']);
        $entries->recordIncome($bank, $rent, 400000, ['date' => '2025-09-01']);

        $income = $this->tax()->incomeByRegime($this->year->id);

        $this->assertSame(1500000.0, $income['by_regime'][TaxSchedule::REGIME_SALARIED]);
        $this->assertSame(400000.0, $income['by_regime'][TaxSchedule::REGIME_RENTAL]);
        $this->assertSame(0.0, $income['unclassified']);
    }

    public function test_untagged_income_is_reported_not_silently_taxed_as_salary(): void
    {
        $bank = PersonalAccount::create([
            'code' => '1100', 'name' => 'Bank', 'type' => PersonalAccount::TYPE_ASSET,
        ]);
        $mystery = PersonalAccount::create([
            'code' => '4900', 'name' => 'Other income', 'type' => PersonalAccount::TYPE_INCOME,
            'tax_regime' => null,
        ]);

        app(PersonalEntryService::class)->recordIncome($bank, $mystery, 250000, ['date' => '2025-09-01']);

        $estimate = $this->tax()->estimate($this->year->id);

        // Guessing a regime would produce a confidently wrong number.
        $this->assertSame(250000.0, $estimate['unclassified']);
        $this->assertSame(0.0, $estimate['total_tax']);
    }

    public function test_the_estimate_totals_every_regime(): void
    {
        $bank = PersonalAccount::create([
            'code' => '1100', 'name' => 'Bank', 'type' => PersonalAccount::TYPE_ASSET,
        ]);
        $salary = PersonalAccount::create([
            'code' => '4000', 'name' => 'Salary', 'type' => PersonalAccount::TYPE_INCOME,
            'tax_regime' => TaxSchedule::REGIME_SALARIED,
        ]);

        app(PersonalEntryService::class)->recordIncome($bank, $salary, 3000000, ['date' => '2025-09-01']);

        $estimate = $this->tax()->estimate($this->year->id);

        $this->assertSame(3000000.0, $estimate['total_income']);
        $this->assertSame(300000.0, $estimate['total_tax']);
        $this->assertCount(1, $estimate['regimes']);
        $this->assertSame('Salaried', $estimate['regimes'][0]['label']);
    }

    public function test_every_seeded_schedule_has_an_unbounded_top_bracket(): void
    {
        // The invariant payroll broke. Asserted over whatever is seeded rather
        // than a fixed list, so a regime added later is covered automatically.
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

        $this->assertSame([], $bounded, implode("\n", [
            'These schedules have a bounded top bracket, so income above it would',
            'match nothing:',
            '',
            ...$bounded,
        ]));
    }
}
