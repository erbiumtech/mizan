<?php

namespace App\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Filters\EmployeeNameFilter;
use App\Nova\Filters\EmployeeEmailFilter;

class Employee extends Resource
{
    public static $model = \App\Models\Employee::class;

    public static $title = 'employee_id';

    public static $search = ['employee_id'];

    /**
     * Shown wherever an Employee is referenced (BelongsTo dropdowns, links).
     */
    public function title(): string
    {
        return $this->employee_id.' - '.($this->user?->name ?? '');
    }

    /**
     * Search by employee code and by the linked user's name.
     */
    public static function searchableColumns(): array
    {
        return ['employee_id', new \Laravel\Nova\Query\Search\SearchableRelation('user', 'name')];
    }

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        if ($request->user()->hasRole('Administrator')) {
            return $query;
        }

        return $query->where('user_id', $request->user()->id);
    }

    public static function relatableUsers(NovaRequest $request, $query)
    {
        return $query->whereHas('roles', fn ($q) => $q->where('name', 'Employee'));
    }

    public function fields(NovaRequest $request): array
    {
        // Employees editing their own record may only change contact and
        // bank details; employment fields stay admin-only.
        $adminOnly = fn ($request) => ! $request->user()->hasRole('Administrator');

        return [
            ID::make()->sortable()->canSee(function ($request) {
                return $request->user()->hasRole('Administrator');
            }),
            Text::make('Employee ID', 'employee_id')->rules('required')->readonly($adminOnly),
            BelongsTo::make('Employee Name', 'user', 'App\Nova\User')->sortable()->readonly(),

            Text::make('Name', 'user_name')
                ->resolveUsing(fn () => $this->user?->name)
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $model->user_name = $request->input($requestAttribute);
                })
                ->rules('required', 'max:255')
                ->onlyOnForms(),

            Text::make('Email', 'user_email')
                ->resolveUsing(fn () => $this->user?->email)
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $model->user_email = $request->input($requestAttribute);
                })
                ->rules('required', 'email', function ($attribute, $value, $fail) {
                    if (\App\Models\User::where('email', $value)->where('id', '!=', $this->user?->id)->exists()) {
                        $fail('The email is already taken.');
                    }
                }),

            Select::make('Status', 'is_active')->options([1 => 'Active', 0 => 'Inactive'])->displayUsingLabels()->readonly($adminOnly),

            Select::make('Designation', 'designation')
                ->options([
                        'Senior Full Stack Developer' => 'Senior Full Stack Developer',
                        'Full Stack Developer' => 'Full Stack Developer',
                        'Frontend Developer' => 'Frontend Developer',
                        'Backend Developer' => 'Backend Developer',
                        'Secretary' => 'Secretary',
                        'Cook' => 'Cook',
                        'Office Boy' => 'Office Boy',
                    ])
                ->displayUsingLabels()
                ->rules('required')
                ->readonly($adminOnly)
                ->hideFromIndex(),

            Select::make('Department', 'department')
                ->options([
                        'IT' => 'IT',
                        'Office Staff' => 'Office Staff',
                    ])
                ->displayUsingLabels()
                ->rules('required')
                ->readonly($adminOnly)
                ->hideFromIndex(),

            Date::make('Date of Joining', 'date_of_joining')->hideFromIndex(),
            Text::make('NIC', 'nic')->hideFromIndex(),

            // Bank Select Field
            BelongsTo::make('Bank', 'bank', Bank::class)
                ->nullable()
                ->searchable()
                ->hideFromIndex()
                ->help('Bank directory for salary bank files'),

            Text::make('Bank Code', 'bank_code')
                ->readonly()
                ->exceptOnForms(),

            Text::make('Bank Short Code', 'bank_short_code')
                ->readonly()
                ->exceptOnForms(),

            Text::make('Bank A/C No', 'bank_account_no')->hideFromIndex(),
            Text::make('IBAN No', 'iban_no')->hideFromIndex(),
            Text::make('Phone', 'phone')->hideFromIndex(),
            Text::make('Address Line 1', 'address_line_1')->hideFromIndex(),
            Text::make('Address Line 2', 'address_line_2')->hideFromIndex(),
            Select::make('Gender', 'gender')->options(['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other'])->hideFromIndex(),

            \Laravel\Nova\Fields\HasMany::make('Change Requests', 'changeRequests', EmployeeChangeRequest::class),
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [
            new EmployeeNameFilter(),
            new EmployeeEmailFilter(),
        ];
    }
}
