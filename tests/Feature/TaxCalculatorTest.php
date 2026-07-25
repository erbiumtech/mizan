<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Services\TaxCalculatorService;
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
}
