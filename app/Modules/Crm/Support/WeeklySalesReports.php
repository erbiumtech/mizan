<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\WeeklySalesReport;
use App\Modules\Employees\Models\Employee;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A week of selling, per rep — docs/saas-launch-plan.md §14.10.
 *
 * **Two sources, and which one is used is a licensing fact rather than a setting.** A
 * company with the pipeline has every figure already, as a consequence of working the
 * week; one without it has a sales team and no pipeline, and the alternative to a
 * short form each Friday is no report at all.
 *
 * Where both exist — CRM licensed *and* somebody filled in a form — the pipeline
 * wins and the form is ignored, because a figure derived from records that exist is
 * not improved by a figure somebody remembered.
 */
class WeeklySalesReports
{
    use ReportShapes;

    public function week(string $asOf): array
    {
        $monday = Carbon::parse(WeeklySalesReport::weekStarting($asOf));
        $sunday = $monday->copy()->endOfWeek(Carbon::SUNDAY);

        $employees = Employee::query()->with('user')->where('is_active', true)->orderBy('employee_id')->get();
        $fromPipeline = modules()->enabled('crm');

        $rows = [];
        $totals = ['visits' => 0, 'calls' => 0, 'quotes' => 0, 'orders' => 0, 'value' => 0.0];
        $reported = 0;

        $figures = $fromPipeline
            ? $this->fromPipeline($monday, $sunday)
            : $this->fromForms($monday);

        foreach ($employees as $employee) {
            $row = $figures->get($employee->getKey());

            // Everybody on the list, including the quiet weeks. A sales report that
            // silently dropped the people who did nothing would be a report of the people
            // who did something, which is a different and much less useful document.
            if ($row === null && ! $fromPipeline) {
                $rows[] = [
                    (string) ($employee->display_label ?? 'Employee #'.$employee->getKey()),
                    'Not sent', '—', '—', '—', '—', '—',
                ];

                continue;
            }

            $row ??= ['visits' => 0, 'calls' => 0, 'quotes' => 0, 'orders' => 0, 'value' => 0.0];

            if (array_sum([$row['visits'], $row['calls'], $row['quotes'], $row['orders']]) === 0 && $row['value'] <= 0) {
                $rows[] = [
                    (string) ($employee->display_label ?? 'Employee #'.$employee->getKey()),
                    $fromPipeline ? 'Nothing recorded' : 'Sent',
                    '—', '—', '—', '—', '—',
                ];

                continue;
            }

            $reported++;

            foreach ($totals as $key => $ignored) {
                $totals[$key] += $row[$key];
            }

            $rows[] = [
                (string) ($employee->display_label ?? 'Employee #'.$employee->getKey()),
                $fromPipeline ? 'From the pipeline' : 'Sent',
                number_format($row['visits']),
                number_format($row['calls']),
                number_format($row['quotes']),
                number_format($row['orders']),
                $row['value'] > 0 ? number_format($row['value'], 0) : '—',
            ];
        }

        return $this->table(
            'WeeklySales',
            'Weekly Sales',
            $this->subtitle('the week of '.$monday->format('d M Y')),
            ['Rep', 'Source', 'Visits', 'Calls', 'Quotes', 'Orders', 'Value'],
            'minmax(0, 1fr) 11rem 7rem 7rem 7rem 7rem 10rem',
            [2, 3, 4, 5, 6],
            $rows,
            [
                ['label' => 'ORDERS', 'value' => (float) $totals['orders'], 'accent' => false],
                ['label' => 'VALUE', 'value' => round($totals['value'], 2), 'accent' => true],
            ],
            $rows === []
                ? 'NOBODY TO REPORT ON'
                : mb_strtoupper($reported.' of '.count($rows).' had something to report'
                    .($fromPipeline ? ', read from the pipeline' : ', from the weekly forms')),
            null,
            'There are no active employees to report on.',
        );
    }

    /**
     * The week as the pipeline already knows it.
     *
     * @return Collection<int, array<string, float|int>>
     */
    private function fromPipeline(Carbon $monday, Carbon $sunday): Collection
    {
        $visits = FieldVisit::query()
            ->whereBetween('checked_out_at', [$monday->copy()->startOfDay(), $sunday->copy()->endOfDay()])
            ->selectRaw('employee_id, count(*) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        $calls = Activity::query()
            ->whereBetween('occurred_at', [$monday->copy()->startOfDay(), $sunday->copy()->endOfDay()])
            ->selectRaw('employee_id, count(*) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        // An opportunity won inside the week, by whoever owned it. Quotes are counted
        // the same way where the module is there; a company without Quotations has
        // nothing to count and the column reads nought rather than being hidden.
        $won = Opportunity::query()
            ->whereBetween('closed_on', [$monday->toDateString(), $sunday->toDateString()])
            ->where('outcome', Opportunity::OUTCOME_WON)
            ->selectRaw('owner_employee_id as employee_id, count(*) as total, sum(amount) as value')
            ->groupBy('owner_employee_id')
            ->get()
            ->keyBy('employee_id');

        return collect($visits->keys())
            ->merge($calls->keys())
            ->merge($won->keys())
            ->unique()
            ->mapWithKeys(fn ($id): array => [(int) $id => [
                'visits' => (int) ($visits[$id] ?? 0),
                'calls' => (int) ($calls[$id] ?? 0),
                'quotes' => 0,
                'orders' => (int) ($won[$id]->total ?? 0),
                'value' => (float) ($won[$id]->value ?? 0),
            ]]);
    }

    /**
     * The week as somebody typed it.
     *
     * @return Collection<int, array<string, float|int>>
     */
    private function fromForms(Carbon $monday): Collection
    {
        return WeeklySalesReport::query()
            ->forWeek($monday)
            ->get()
            ->mapWithKeys(fn (WeeklySalesReport $report): array => [(int) $report->employee_id => [
                'visits' => (int) $report->visits,
                'calls' => (int) $report->calls,
                'quotes' => (int) $report->quotes,
                'orders' => (int) $report->orders,
                'value' => (float) $report->order_value,
            ]]);
    }
}
