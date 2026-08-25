<?php

namespace App\Modules\Payroll\Reporting;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\Payslip;
use App\Support\EmployeeAccess;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payslips — `docs/reports-expansion-plan.md` Phase 6, items 1 and 2.
 *
 * **The subject item 6 has in mind when it says "a company-wide custom report over payslips is a payroll
 * leak".** Two things stand in the way of that here and both are declarations rather than checks: the
 * permission is `PayslipView`, so somebody who cannot open a payslip cannot build over them; and `access()`
 * applies the same `EmployeeAccess` scoping `PayslipResource` applies, so a manager who can open payslips sees
 * their own and their downline's and nobody else's — through the builder as through the resource.
 *
 * **Row-level access is inherited, not re-implemented** (item 2). `EmployeeAccess::scopeAccessibleEmployees()`
 * is the same service the resource calls, on the same column. That matters more than it looks: the accessible
 * set is a transitive walk of the manager hierarchy, and a second implementation of it would be a second
 * answer to "who reports to whom" that agrees today and diverges the first time the walk changes.
 *
 * **A payslip has no period, and the reason is a column.** `payslips.month` holds a month *name* —
 * 'January' — so it cannot be ordered or bounded: `'January' >= '2026-07-01'` is a string comparison the
 * database will answer and nobody can predict. What stands in for a period is the fiscal year, which is a real
 * foreign key and the thing payroll is actually filed against, plus the month as a select. So this subject
 * takes item 5's other branch, the row cap.
 *
 * **The named earnings columns are here and the components are a subject of their own.** This table has
 * `basic_wage`, `medical_allowance` and four more as columns, because that is how this payroll was built; a
 * company that adds a component gets a `payslip_components` row instead, which is why both subjects exist. A
 * report that wants "everything paid to everybody" wants the components; one that wants the shape of a
 * payslip wants this.
 */
class PayslipDataset extends Dataset
{
    public static function label(): string
    {
        return 'Payslips';
    }

    public static function description(): string
    {
        return "One employee's pay for one month: attendance, earnings, deductions and the net.";
    }

    public static function model(): string
    {
        return Payslip::class;
    }

    public static function permission(): string
    {
        return 'PayslipView';
    }

    /** No period: see the class docblock — `payslips.month` is a month name, not a date. */
    public static function periodColumn(): ?string
    {
        return null;
    }

    /**
     * Own rows and the downline's, unless privileged — the same rule `PayslipResource` applies.
     *
     * Applied to the base query rather than to a report's own filters, so there is no report state, no column
     * choice and no aggregate that can reach a row this excludes. A `SUM(net_salary)` over a manager's report
     * totals their downline, which is the only answer that is both useful and theirs.
     */
    protected static function access(Builder $query): Builder
    {
        return app(EmployeeAccess::class)->scopeAccessibleEmployees($query, auth()->user(), 'employee_id');
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('month', 'Month'),
            DatasetColumn::related('fiscal_year', 'Financial year', 'fiscalYear.name', groupBy: 'fiscal_year_id'),
            DatasetColumn::related('employee', 'Employee', 'employee.name', groupBy: 'employee_id'),
            DatasetColumn::related('department', 'Department', 'employee.department'),
            DatasetColumn::related('designation', 'Designation', 'employee.designation'),

            DatasetColumn::make('total_working_days', 'Working days', DatasetColumn::NUMBER),
            DatasetColumn::make('paid_days', 'Paid days', DatasetColumn::NUMBER),
            DatasetColumn::make('lop_days', 'Unpaid days', DatasetColumn::NUMBER),
            DatasetColumn::make('leaves_taken', 'Leave taken', DatasetColumn::NUMBER),

            DatasetColumn::make('basic_wage', 'Basic', DatasetColumn::MONEY),
            DatasetColumn::make('medical_allowance', 'Medical', DatasetColumn::MONEY),
            DatasetColumn::make('device_allowance', 'Device', DatasetColumn::MONEY),
            DatasetColumn::make('petrol_allowance', 'Petrol', DatasetColumn::MONEY),
            DatasetColumn::make('bonus', 'Bonus', DatasetColumn::MONEY),
            DatasetColumn::make('total_earnings', 'Earnings', DatasetColumn::MONEY),

            DatasetColumn::make('withholding_tax', 'Tax', DatasetColumn::MONEY),
            DatasetColumn::make('advances', 'Advances', DatasetColumn::MONEY),
            DatasetColumn::make('meal_deduction', 'Meals', DatasetColumn::MONEY),
            DatasetColumn::make('esi_health_insurance', 'Health', DatasetColumn::MONEY),
            DatasetColumn::make('total_deductions', 'Deductions', DatasetColumn::MONEY),

            DatasetColumn::make('net_salary', 'Net', DatasetColumn::MONEY),
        ];
    }

    public static function filters(): array
    {
        return [
            // The fiscal year is the period, in the only form this table supports. Ordered newest first
            // because the report somebody wants is nearly always the current year or the last one.
            DatasetFilter::select(
                'fiscal_year',
                'Financial year',
                'fiscal_year_id',
                fn (): array => FiscalYear::query()->orderByDesc('start_date')->pluck('name', 'id')->all(),
            ),
            /*
             * The months that exist, read off the payslips themselves.
             *
             * Not a list of the twelve month names: this column is free text and what is in it is whatever
             * payroll has been run as, so offering a name no payslip carries would be offering a filter that
             * returns nothing. `distinct` through the model keeps it inside the access scoping above, so a
             * manager's month list is their downline's months.
             */
            DatasetFilter::select(
                'month',
                'Month',
                'month',
                fn (): array => static::query()
                    ->whereNotNull('month')
                    ->distinct()
                    ->orderBy('month')
                    ->pluck('month', 'month')
                    ->all(),
            ),
        ];
    }
}
