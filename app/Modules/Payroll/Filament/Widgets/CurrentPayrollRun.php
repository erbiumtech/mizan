<?php

namespace App\Modules\Payroll\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Payroll\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\Widget;

/**
 * The payroll run in progress — the second card under the summary row of the Material dashboard.
 *
 * The latest run that is not yet locked, with its pay day, how many payslips it holds, how many employees have
 * accepted theirs (the progress bar), and the way to review it. With no run in progress the card says so and
 * points at the runs list, rather than disappearing and leaving the approvals card alone on its row.
 *
 * Lazy, and `canView()` is a licence-and-permission check only, for the budget reason DashboardSummaryCards
 * gives: whether a run exists is a query, and it runs on the widget's own request.
 */
class CurrentPayrollRun extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.current-payroll-run';

    protected int|string|array $columnSpan = 1;

    /** Beside "Waiting for you", under the summary cards. */
    protected static ?int $sort = DashboardWidgets::HEADLINE + 2;

    protected static bool $isLazy = true;

    protected ?string $pollingInterval = null;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    public static function canView(): bool
    {
        return static::moduleIsAvailable() && (bool) auth()->user()?->can('PayrollRunView');
    }

    public function run(): ?PayrollRun
    {
        return PayrollRun::query()
            ->where('status', '!=', PayrollRun::STATUS_LOCKED)
            ->with('fiscalYear')
            ->latest('id')
            ->first();
    }

    /**
     * What the card shows about a run.
     *
     * @return array{label: string, status: string, statusTone: string, payslips: int, accepted: int, percent: int, payDay: ?string}
     */
    public function summary(PayrollRun $run): array
    {
        $totals = $run->totals();
        $payslips = (int) $totals['payslips'];
        $accepted = (int) $totals['accepted'];

        return [
            'label' => $run->periodLabel(),
            'status' => $run->isApproved() ? __('Approved') : __('Open'),
            'statusTone' => $run->isApproved() ? 'primary' : 'secondary',
            'payslips' => $payslips,
            'accepted' => $accepted,
            'percent' => $payslips > 0 ? (int) round($accepted / $payslips * 100) : 0,
            'payDay' => $run->payDay()?->format('j F'),
        ];
    }

    public function runsUrl(): ?string
    {
        return rescue(fn (): string => PayrollRunResource::getUrl('index'), null, false);
    }

    public function payslipsUrl(): ?string
    {
        return rescue(fn (): string => PayslipResource::getUrl('index'), null, false);
    }
}
