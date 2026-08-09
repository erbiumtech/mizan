<?php

namespace Tests\Feature;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Payroll\Services\TaxCalculatorService;
use Tests\AccountingTestCase;

class TaxCalculatorTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The expected numbers below encode the 2025-2026 salaried slabs, so
        // resolve that fiscal year explicitly (the shared base uses 2026-2027).
        $this->fiscalYear = FiscalYear::where('name', '2025-2026')->firstOrFail();
    }

    private function calc(): TaxCalculatorService
    {
        return app(TaxCalculatorService::class);
    }

    public function test_income_up_to_600k_is_tax_free(): void
    {
        $this->assertSame(0.0, $this->calc()->annualTax(500000, $this->fiscalYear->id));
        $this->assertSame(0.0, $this->calc()->annualTax(600000, $this->fiscalYear->id));
    }

    public function test_second_slab_is_one_percent_of_excess(): void
    {
        // 1% of (1,000,000 - 600,000)
        $this->assertSame(4000.0, $this->calc()->annualTax(1000000, $this->fiscalYear->id));
        // top of slab 2
        $this->assertSame(6000.0, $this->calc()->annualTax(1200000, $this->fiscalYear->id));
    }

    public function test_middle_slabs_use_fixed_tax_plus_percentage(): void
    {
        // 6,000 + 11% of (2,000,000 - 1,200,000)
        $this->assertSame(94000.0, $this->calc()->annualTax(2000000, $this->fiscalYear->id));
        // 116,000 + 23% of (3,000,000 - 2,200,000)
        $this->assertSame(300000.0, $this->calc()->annualTax(3000000, $this->fiscalYear->id));
        // 346,000 + 30% of (4,000,000 - 3,200,000)
        $this->assertSame(586000.0, $this->calc()->annualTax(4000000, $this->fiscalYear->id));
    }

    public function test_top_slab_has_no_upper_bound(): void
    {
        // 616,000 + 35% of (10,000,000 - 4,100,000)
        $this->assertSame(2681000.0, $this->calc()->annualTax(10000000, $this->fiscalYear->id));
    }

    public function test_monthly_tax_is_annual_divided_by_twelve(): void
    {
        $this->assertSame(25000.0, $this->calc()->monthlyTax(3000000, $this->fiscalYear->id));
    }

    public function test_zero_and_negative_income_produce_no_tax(): void
    {
        $this->assertSame(0.0, $this->calc()->annualTax(0, $this->fiscalYear->id));
        $this->assertSame(0.0, $this->calc()->annualTax(-5, $this->fiscalYear->id));
    }

    public function test_unknown_fiscal_year_produces_no_tax(): void
    {
        $this->assertSame(0.0, $this->calc()->annualTax(3000000, 99999));
    }

    /**
     * Every other case here pins 2025-2026, whose top slab is correctly
     * unbounded. This one guards the *other* seeded year, because the failure
     * mode is silent: annualTax() matches `max_amount >= income OR max_amount
     * IS NULL`, so a capped top slab means a high enough income matches nothing
     * and the method returns 0.0 — no exception, no warning, no tax. The
     * highest earners in the company are exactly who it would go wrong for.
     */
    public function test_the_top_slab_of_every_seeded_year_is_unbounded(): void
    {
        $capped = FiscalYear::query()
            ->whereHas('salarySlabs')
            ->get()
            ->filter(function (FiscalYear $year) {
                $top = SalarySlab::where('fiscal_year_id', $year->id)
                    ->orderByDesc('min_amount')
                    ->first();

                return $top !== null && $top->max_amount !== null;
            })
            ->map(fn (FiscalYear $year) => $year->name)
            ->values()
            ->all();

        $this->assertSame([], $capped, implode("\n", [
            'The top salary slab of these fiscal years has an upper bound, so any',
            'taxable income above it matches no slab and is silently taxed at zero:',
            '',
            ...$capped,
        ]));
    }

    public function test_income_above_the_top_threshold_is_still_taxed(): void
    {
        foreach (FiscalYear::whereHas('salarySlabs')->get() as $year) {
            $top = SalarySlab::where('fiscal_year_id', $year->id)
                ->orderByDesc('min_amount')
                ->firstOrFail();

            // Comfortably past any plausible cap.
            $income = ((float) $top->min_amount) * 20 + 1_000_000;

            $this->assertGreaterThan(
                0.0,
                $this->calc()->annualTax($income, $year->id),
                "{$year->name}: income of {$income} produced no tax at all.",
            );
        }
    }
}
