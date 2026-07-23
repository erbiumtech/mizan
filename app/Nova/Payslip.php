<?php

namespace App\Nova;

use App\Nova\Actions\DownloadPayslip;
use App\Nova\Filters\EmployeeFilter;
use App\Nova\Filters\FiscalYearFilter;
use App\Nova\Filters\MonthFilter;
use App\Services\PayslipService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Query\Search\SearchableRelation;

class Payslip extends Resource
{
    public static $model = \App\Models\Payslip::class;

    public static $title = 'id';

    // Global search columns definition
    public static function searchableColumns(): array
    {
        return [
            'id',
            'month',
            new SearchableRelation('employee', 'employee_id'),
            new SearchableRelation('employee.user', 'name'),
            new SearchableRelation('fiscalYear', 'name'),
        ];
    }

    public function fields(NovaRequest $request)
    {
        // Saari editable fields jo calculation effect karti hain
        $selectors = ['employee', 'month', 'fiscalYear'];

        $calcDeps = array_merge($selectors, ['bonus', 'extra_work_hours', 'device_allowance', 'petrol_allowance', 'advances', 'meal_deduction', 'esi_health_insurance']);

        return [
            ID::make()->sortable()->hideFromIndex(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->searchable()
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

            // --- ATTENDANCE (Text fields with numeric validation) ---
            Text::make('Total Working Days', 'total_working_days')
                ->rules('required', 'numeric', 'min:0')
                ->default(0)
                ->hideFromIndex(),

            Text::make('Paid Days', 'paid_days')
                ->rules('required', 'numeric', 'min:0')
                ->default(0)
                ->hideFromIndex(),

            Text::make('LOP Days', 'lop_days')
                ->rules('required', 'numeric', 'min:0')
                ->default(0)
                ->hideFromIndex(),

            Text::make('Leaves Taken', 'leaves_taken')
                ->rules('required', 'numeric', 'min:0')
                ->default(0)
                ->hideFromIndex(),

            // READONLY FIELDS
            Text::make('Basic Wage', 'basic_wage')->readonly()
                ->dependsOn(['employee', 'month', 'fiscalYear'], fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'basic_wage'))
                ->hideFromIndex(),

            Text::make('Medical Allowance', 'medical_allowance')->readonly()
                ->dependsOn(['employee', 'month', 'fiscalYear'], fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'medical_allowance'))
                ->hideFromIndex(),

            // EDITABLE FIELDS (Text fields with numeric validation)
            Text::make('Device Allowance', 'device_allowance')
                ->rules('nullable', 'numeric', 'min:0')
                ->dependsOn($selectors, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'device_allowance'))
                ->hideFromIndex(),

            Text::make('Petrol Allowance', 'petrol_allowance')
                ->rules('nullable', 'numeric', 'min:0')
                ->dependsOn($selectors, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'petrol_allowance'))
                ->hideFromIndex(),

            Text::make('Bonus', 'bonus')
                ->rules('nullable', 'numeric', 'min:0')
                ->dependsOn($selectors, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'bonus'))
                ->hideFromIndex(),

            Text::make('Extra Work Hours', 'extra_work_hours')
                ->rules('nullable', 'numeric', 'min:0')
                ->dependsOn($selectors, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'extra_work_hours'))
                ->hideFromIndex(),

            Text::make('Advances', 'advances')
                ->rules('nullable', 'numeric', 'min:0')
                ->dependsOn($selectors, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'advances'))
                ->hideFromIndex(),

            Text::make('Meal Deduction', 'meal_deduction')
                ->rules('nullable', 'numeric', 'min:0')
                ->dependsOn($selectors, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'meal_deduction'))
                ->hideFromIndex(),

            Text::make('ESI / Health Insurance', 'esi_health_insurance')
                ->rules('nullable', 'numeric', 'min:0')
                ->dependsOn($selectors, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'esi_health_insurance'))
                ->hideFromIndex(),

            // CALCULATED FIELDS
            Text::make('Withholding Tax', 'withholding_tax')->readonly()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'withholding_tax'))
                ->hideFromIndex(),

            Text::make('Total Earnings', 'total_earnings')->readonly()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'total_earnings'))
                ->hideFromIndex(),

            Text::make('Total Deductions', 'total_deductions')->readonly()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'total_deductions'))
                ->hideFromIndex(),

            Text::make('Net Salary', 'net_salary')->readonly()->sortable()
                ->dependsOn($calcDeps, fn ($f, $r, $d) => $this->updateCalculatedFields($f, $d, 'net_salary')),

            Badge::make('Employee Review', 'employee_review')
                ->map([
                    'pending' => 'warning',
                    'accepted' => 'success',
                    'rejected' => 'danger',
                ])
                ->sortable()
                ->exceptOnForms(),

            DateTime::make('Reviewed At', 'employee_reviewed_at')->onlyOnDetail(),
            Text::make('Rejection Reason', 'employee_rejection_reason')->onlyOnDetail(),

            MorphMany::make('Comments', 'comments', Comment::class),
        ];
    }

    /**
     * Employees see only their own payslips; staff see all. Also handle custom relationship searching.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        $user = $request->user();

        if ($user->hasRole('Employee') && ! $user->hasAnyRole(['Administrator', 'Accountant', 'Manager', 'CEO'])) {
            $query->whereHas('employee', fn ($q) => $q->where('user_id', $user->id));
        }

        // Handle Search across Employee ID, User Name, and Fiscal Year Name
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->orWhere('id', 'like', "%{$search}%")
                    ->orWhere('month', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($empQuery) use ($search) {
                        $empQuery->where('employee_id', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('name', 'like', "%{$search}%");
                            });
                    })
                    ->orWhereHas('fiscalYear', function ($fyQuery) use ($search) {
                        $fyQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    protected function updateCalculatedFields($field, FormData $formData, $key)
    {
        if ($formData->employee && $formData->month && $formData->fiscalYear) {
            $data = app(PayslipService::class)->calculateByParams(
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
            new Actions\AcceptPayslip,
            new Actions\RejectPayslip,
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [
            new EmployeeFilter,
            new MonthFilter,
            new FiscalYearFilter,
        ];
    }
}
