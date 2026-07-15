<?php

namespace App\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Employee extends Resource
{
    public static $model = \App\Models\Employee::class;

    public static $title = 'employee_id';

    public static $search = ['employee_id'];

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
        return [
            ID::make()->sortable()->canSee(function ($request) {
                return ! $request->user()->hasRole('Administrator || Employee');
            }),
            Text::make('Employee ID', 'employee_id')->rules('required'),
            BelongsTo::make('Employee Name', 'user', 'App\Nova\User')->sortable()->readonly(),
            Text::make('Email', 'user.email')->onlyOnIndex(),

            Select::make('Status', 'is_active')->options([1 => 'Active', 0 => 'Inactive'])->displayUsingLabels(),

            Select::make('Designation', 'designation')
                ->options([
                        'Senior Full Stack Developer' => 'Senior Full Stack Developer',
                        'Full Stack Developer' => 'Full Stack Developer',
                        'Frontend Developer' => 'Frontend Developer',
                        'Backend Developer' => 'Backend Developer',
                        'Cook' => 'Cook',
                        'Office Boy' => 'Office Boy',
                    ])
                ->displayUsingLabels()
                ->rules('required')
                ->hideFromIndex(),

            Select::make('Department', 'department')
                ->options([
                        'IT' => 'IT',
                        'Office Staff' => 'Office Staff',
                    ])
                ->displayUsingLabels()
                ->rules('required')
                ->hideFromIndex(),
                
            Date::make('Date of Joining', 'date_of_joining')->hideFromIndex(),
            Text::make('NIC', 'nic')->hideFromIndex(),
            \Laravel\Nova\Fields\BelongsTo::make('Bank', 'bank', Bank::class)->nullable()->searchable()->hideFromIndex()->help('Bank directory (IMD codes) for salary bank files'),
            Text::make('Bank Name', 'bank_name')->hideFromIndex(),
            Text::make('Bank A/C No', 'bank_account_no')->hideFromIndex(),
            Text::make('IBAN No', 'iban_no')->hideFromIndex(),
            Text::make('Phone', 'phone')->hideFromIndex(),
            Text::make('Address Line 1', 'address_line_1')->hideFromIndex(),
            Text::make('Address Line 2', 'address_line_2')->hideFromIndex(),
            Select::make('Gender', 'gender')->options(['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other'])->hideFromIndex(),
        ];
    }
}
