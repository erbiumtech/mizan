<?php

namespace App\Modules\Payroll\Reporting;

use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\PayslipComponent;
use App\Support\EmployeeAccess;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payslip components — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **The subject that answers "what did we pay in allowances this year", which the payslip subject cannot.**
 * A payslip's named columns are fixed at build time; a company that adds a component adds rows here. So the
 * question "everything paid, by kind of pay" is one row per component per payslip, grouped by component —
 * which is this subject and nothing else.
 *
 * **Access reaches through the payslip, and that is the interesting line in the file.** These rows have no
 * `employee_id`; the employee is on the payslip. So `access()` is a `whereHas` on the payslip with the same
 * `EmployeeAccess` scoping the payslip subject applies directly. Without it, this subject would be the way
 * around the manager hierarchy that item 2 exists to prevent — the leak would not be in the payslips at all,
 * it would be in their components, and a report over them would read like a harmless summary.
 *
 * **The component's label is the grouping axis and `pay_component_id` is what it groups on.** The label can be
 * edited; the id cannot, so a report grouped by component keeps its buckets when somebody renames "Petrol" to
 * "Fuel" — and shows the new name, which is what they wanted.
 */
class PayslipComponentDataset extends Dataset
{
    public static function label(): string
    {
        return 'Payslip components';
    }

    public static function description(): string
    {
        return 'One component of one payslip — an allowance or a deduction, and what it came to.';
    }

    public static function model(): string
    {
        return PayslipComponent::class;
    }

    public static function permission(): string
    {
        return 'PayslipView';
    }

    /**
     * No period, for the reason the payslip subject has none: the month it belongs to is a name.
     *
     * This subject is the one most in need of the row cap — a company of two hundred people paying six
     * components is 14,400 rows a year — which is why the component and the financial year are both filters.
     */
    public static function periodColumn(): ?string
    {
        return null;
    }

    /**
     * Own rows and the downline's, through the payslip.
     *
     * `whereHas` rather than a join, so the scoping is a subquery the database can index-satisfy and the
     * declaration stays inside the relation the model already has. The closure re-uses `EmployeeAccess`
     * exactly as `PayslipDataset` does; two callers of one service rather than two implementations of one walk.
     */
    protected static function access(Builder $query): Builder
    {
        return $query->whereHas(
            'payslip',
            fn (Builder $payslips) => app(EmployeeAccess::class)
                ->scopeAccessibleEmployees($payslips, auth()->user(), 'employee_id'),
        );
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::related('component', 'Component', 'component.label', groupBy: 'pay_component_id'),
            DatasetColumn::related('component_code', 'Code', 'component.code', groupBy: 'pay_component_id'),
            DatasetColumn::related('component_kind', 'Kind', 'component.kind', groupBy: 'pay_component_id'),
            DatasetColumn::related('taxable', 'Taxable', 'component.is_taxable', DatasetColumn::BOOLEAN),

            DatasetColumn::related('month', 'Month', 'payslip.month'),
            DatasetColumn::related('employee', 'Employee', 'payslip.employee.name'),
            DatasetColumn::related('fiscal_year', 'Financial year', 'payslip.fiscalYear.name'),

            DatasetColumn::make('amount', 'Amount', DatasetColumn::MONEY),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::select(
                'component',
                'Component',
                'pay_component_id',
                // Every component, active or not: a report about last year needs the one that was retired in
                // March, and `PayrollRegister` makes the same choice for the same reason.
                fn (): array => PayComponent::query()->orderBy('sort')->pluck('label', 'id')->all(),
            ),
        ];
    }
}
