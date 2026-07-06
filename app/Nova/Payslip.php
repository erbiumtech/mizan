<?php

namespace App\Nova;

use App\Nova\Actions\DownloadPayslip;
use App\Services\PayslipService;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class Payslip extends Resource
{
    public static $model = \App\Models\Payslip::class;

    public static $title = 'id';

    public static $search = ['id'];

    public function fields(NovaRequest $request)
    {
        // Saari editable fields jo calculation effect karti hain
        $calcDeps = ['employee', 'month', 'fiscalYear', 'bonus', 'extra_work_hours', 'device_allowance', 'petrol_allowance', 'advances', 'meal_deduction', 'esi_health_insurance'];

        return [
            ID::make()->sortable()->hideFromIndex(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->rules('required', function ($attribute, $value, $fail) use ($request) {
                    $exists = \App\Models\Payslip::where('employee_id', $value)
                        ->where('month', $request->month)
                        ->where('fiscal_year_id', $request->fiscalYear)
                        
                        ->where('id', '!=', $request->resourceId)
                        ->exists();

                    if ($exists) {
                        $fail('Payslip for this month and fiscal year of this employee is already created. Please update the old one');
                    }
                }),

            Select::make('Month', 'month')->options([
                'January' => 'January', 'February' => 'February', 'March' => 'March',
                'April' => 'April', 'May' => 'May', 'June' => 'June',
                'July' => 'July', 'August' => 'August', 'September' => 'September',
                'October' => 'October', 'November' => 'November', 'December' => 'December',
            ])->rules('required'),

            BelongsTo::make('Fiscal Year', 'fiscalYear', FiscalYear::class)
                ->rules('required')
                ->relatableQueryUsing(fn ($request, $query) => $query->where('is_active', true)),

            Number::make('Total Working Days', 'total_working_days')->min(0)->default(0)->hideFromIndex(),
            Number::make('Paid Days', 'paid_days')->min(0)->default(0)->hideFromIndex(),
            Number::make('LOP Days', 'lop_days')->min(0)->default(0)->hideFromIndex(),
            Number::make('Leaves Taken', 'leaves_taken')->min(0)->default(0)->hideFromIndex(),

            // READONLY FIELDS
            Number::make('Basic Wage', 'basic_wage')->step(0.01)->readonly()
                ->dependsOn(['employee', 'month', 'fiscalYear'], fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'basic_wage'))
                ->hideFromIndex(),

            Number::make('Medical Allowance', 'medical_allowance')->step(0.01)->readonly()
                ->dependsOn(['employee', 'month', 'fiscalYear'], fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'medical_allowance'))
                ->hideFromIndex(),

            // EDITABLE FIELDS
            Number::make('Device Allowance', 'device_allowance')->step(0.01)
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'device_allowance'))
                ->hideFromIndex(),

            Number::make('Petrol Allowance', 'petrol_allowance')->step(0.01)
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'petrol_allowance'))
                ->hideFromIndex(),

            Number::make('Bonus', 'bonus')->step(0.01)
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'bonus'))
                ->hideFromIndex(),

            Number::make('Extra Work Hours', 'extra_work_hours')->step(0.01)
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'extra_work_hours'))
                ->hideFromIndex(),

            Number::make('Advances', 'advances')->step(0.01)
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'advances'))
                ->hideFromIndex(),

            Number::make('Meal Deduction', 'meal_deduction')->step(0.01)
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'meal_deduction'))
                ->hideFromIndex(),

            Number::make('ESI / Health Insurance', 'esi_health_insurance')->step(0.01)
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'esi_health_insurance'))
                ->hideFromIndex(),

            // CALCULATED FIELDS
            Number::make('Withholding Tax', 'withholding_tax')->step(0.01)->readonly()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'withholding_tax'))
                ->hideFromIndex(),

            Number::make('Total Earnings', 'total_earnings')->step(0.01)->readonly()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'total_earnings'))
                ->hideFromIndex(),

            Number::make('Total Deductions', 'total_deductions')->step(0.01)->readonly()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'total_deductions'))
                ->hideFromIndex(),

            Number::make('Net Salary', 'net_salary')->step(0.01)->readonly()->sortable()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'net_salary')),
        ];
    }

    protected function updateCalculatedFields($field, FormData $formData, $key)
    {
        if ($formData->employee && $formData->month && $formData->fiscalYear) {
            $data = (new PayslipService)->calculateByParams(
                $formData->employee,
                $formData->month,
                $formData->fiscalYear,
                (float) ($formData->bonus ?? 0),
                (float) ($formData->extra_work_hours ?? 0),
                (float) ($formData->device_allowance ?? 0),
                (float) ($formData->petrol_allowance ?? 0),
                (float) ($formData->advances ?? 0),
                (float) ($formData->meal_deduction ?? 0),
                (float) ($formData->esi_health_insurance ?? 0)
            );

            $field->value = ($data && isset($data[$key])) ? $data[$key] : 0;
        } else {
            $field->value = 0;
        }
    }

    public function actions(NovaRequest $request)
    {
        return [
            (new DownloadPayslip)->showOnTableRow()->withoutConfirmation(),
        ];
    }
}
