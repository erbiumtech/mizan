<?php

namespace App\Modules\Payroll\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Payroll\Services\TaxCalculatorService;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\Widget;

/**
 * "If my salary is X, what do I take home?" — on the dashboard, for everyone.
 *
 * The same question the salary-calculator pages of the tax-filing services
 * answer, computed by the same TaxCalculatorService that computes the payslips —
 * so the number an employee sees here is the number payroll will deduct, not a
 * near-miss from a second implementation. Deliberately visible to every signed-in
 * user of a company with payroll, employees included: the slabs are public law,
 * the input is whatever the viewer types, and nothing of anyone else's pay is
 * read or shown.
 *
 * The active fiscal year's slabs only. A year with no slabs seeded says so
 * rather than answering zero — the silent-zero footgun the personal estimate's
 * docblock records is not getting a third life here.
 */
class SalaryTaxCalculator extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.salary-tax-calculator';

    /** Instant arithmetic — nothing to defer, nothing to poll. */
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected static ?int $sort = DashboardWidgets::PEOPLE + 5;

    /** What the viewer typed: monthly gross salary, PKR. */
    public ?string $monthly = null;

    public static function canView(): bool
    {
        return static::moduleIsAvailable();
    }

    /**
     * @return array{monthly: float, annual: float, monthly_tax: float, annual_tax: float, take_home: float, effective_rate: float, year: ?string, no_slabs: bool}
     */
    public function compute(): array
    {
        $year = FiscalYear::where('is_active', true)->first();
        $monthly = round(max(0, (float) preg_replace('/[^0-9.]/', '', (string) $this->monthly)), 2);
        $annual = round($monthly * 12, 2);

        $hasSlabs = $year && SalarySlab::where('fiscal_year_id', $year->id)->exists();

        $annualTax = $hasSlabs ? app(TaxCalculatorService::class)->annualTax($annual, $year->id) : 0.0;
        $monthlyTax = round($annualTax / 12, 2);

        return [
            'monthly' => $monthly,
            'annual' => $annual,
            'monthly_tax' => $monthlyTax,
            'annual_tax' => $annualTax,
            'take_home' => round($monthly - $monthlyTax, 2),
            'effective_rate' => $annual > 0 ? round($annualTax / $annual * 100, 2) : 0.0,
            'year' => $year?->name,
            'no_slabs' => ! $hasSlabs,
        ];
    }
}
