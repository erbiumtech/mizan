<?php

namespace App\Nova;

use App\Models\EmployeeSetting;
use App\Nova\Actions\DownloadPayslip;
use App\Services\PayslipService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class Payslip extends Resource
{
    public static $model = \App\Models\Payslip::class;

    public static $title = 'id';

    public static $search = ['id', 'pay_period'];

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        if ($request->user()->hasRole('Administrator')) {
            return $query;
        }

        return $query->whereHas('employee', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        });
    }

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable()->hideFromIndex(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->rules('required'),

            Select::make('Version', 'version_id')
                ->options([])
                ->dependsOn(
                    ['employee'],
                    function (Select $field, NovaRequest $request, $formData) {
                        $employeeId = $formData->employee;
                        if ($employeeId) {
                            $options = EmployeeSetting::where('employee_id', $employeeId)
                                ->pluck('version_id', 'version_id')
                                ->toArray();
                            $field->options($options);
                        } else {
                            $field->options([]);
                        }
                    }
                )
                ->rules('required'),

            Date::make('Pay Period', 'pay_period')->hideFromIndex(),

            Number::make('Total Working Days', 'total_working_days')->min(0)->default(0)->hideFromIndex(),
            Number::make('Paid Days', 'paid_days')->min(0)->default(0)->hideFromIndex(),
            Number::make('LOP Days', 'lop_days')->min(0)->default(0)->hideFromIndex(),
            Number::make('Leaves Taken', 'leaves_taken')->min(0)->default(0)->hideFromIndex(),

            // --- AUTO FIELDS DEPENDING ON VERSION ---

            Number::make('Extra Work Hours', 'extra_work_hours')
                ->step(0.01)->default(0)->readonly()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['extra_work_hours'] ?? 0;
                    }
                })->hideFromIndex(),

            Number::make('Bonus', 'bonus')
                ->step(0.01)->default(0)->readonly()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id, $formData->bonus ?? 0);
                        $field->value = $data['bonus'] ?? 0;
                    }
                })->hideFromIndex(),

            Number::make('Basic Wage', 'basic_wage')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['basic_wage'] ?? 0;
                    }
                }),

            Number::make('Medical Allowance', 'medical_allowance')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['medical_allowance'] ?? 0;
                    }
                }),

            Number::make('Device Allowance', 'device_allowance')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['device_allowance'] ?? 0;
                    }
                }),

            Number::make('Petrol Allowance', 'petrol_allowance')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['petrol_allowance'] ?? 0;
                    }
                }),

            Number::make('Advances', 'advances')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['advances'] ?? 0;
                    }
                }),

            Number::make('Meal Deduction', 'meal_deduction')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['meal_deduction'] ?? 0;
                    }
                }),

            Number::make('ESI / Health Insurance', 'esi_health_insurance')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id);
                        $field->value = $data['esi_health_insurance'] ?? 0;
                    }
                }),

            Number::make('Withholding Tax', 'withholding_tax')->step(0.01)->readonly()
                ->dependsOn(['version_id', 'bonus', 'pay_period'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id && $formData->pay_period) {
                        $data = (new PayslipService)->calculateByVersion(
                            $formData->version_id,
                            $formData->bonus ?? 0,
                            0,
                            $formData->pay_period
                        );
                        $field->value = $data['withholding_tax'] ?? 0;
                    }
                }),

            Number::make('Total Earnings', 'total_earnings')->step(0.01)->readonly()->hideFromIndex()
                ->dependsOn(['version_id', 'bonus'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id) {
                        $data = (new PayslipService)->calculateByVersion($formData->version_id, $formData->bonus ?? 0);
                        $field->value = $data['total_earnings'] ?? 0;
                    }
                }),

            // Total Deductions Field
            Number::make('Total Deductions', 'total_deductions')->step(0.01)->readonly()
                ->dependsOn(['version_id', 'bonus', 'pay_period'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id && $formData->pay_period) {
                        $data = (new PayslipService)->calculateByVersion(
                            $formData->version_id,
                            $formData->bonus ?? 0,
                            0,
                            $formData->pay_period
                        );
                        $field->value = $data['total_deductions'] ?? 0;
                    }
                }),

            // Net Salary Field
            Number::make('Net Salary', 'net_salary')->step(0.01)->readonly()->sortable()
                ->dependsOn(['version_id', 'bonus', 'pay_period'], function (Number $field, NovaRequest $request, FormData $formData) {
                    if ($formData->version_id && $formData->pay_period) {
                        $data = (new PayslipService)->calculateByVersion(
                            $formData->version_id,
                            $formData->bonus ?? 0,
                            0,
                            $formData->pay_period
                        );
                        $field->value = $data['net_salary'] ?? 0;
                    }
                }),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [
            (new DownloadPayslip)->showOnTableRow()->withoutConfirmation(),
        ];
    }
}
