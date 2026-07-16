<?php

namespace App\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class EmployeeChangeRequest extends Resource
{
    public static $model = \App\Models\EmployeeChangeRequest::class;

    public static $title = 'id';

    public static $search = ['id'];

    public static $group = 'Employees';

    // Requests are created by editing your own Employee record.
    public static function authorizedToCreate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(\Illuminate\Http\Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    /**
     * Approvers see every request; employees only their own.
     */
    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        if ($request->user()->can('EmployeeChangeApprove')) {
            return $query;
        }

        return $query->where('requested_by', $request->user()->id);
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Employee', 'employee', Employee::class)->sortable(),

            BelongsTo::make('Requested By', 'requester', User::class),

            KeyValue::make('Requested Changes', 'requested_changes')
                ->keyLabel('Field')
                ->valueLabel('New Value'),

            KeyValue::make('Current Values', 'original_values')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->onlyOnDetail(),

            Badge::make('Status', 'status')->map([
                'pending' => 'warning',
                'approved' => 'success',
                'rejected' => 'danger',
            ])->sortable(),

            BelongsTo::make('Reviewed By', 'reviewer', User::class)->nullable()->onlyOnDetail(),
            DateTime::make('Reviewed At', 'reviewed_at')->onlyOnDetail(),
            Text::make('Rejection Reason', 'rejection_reason')->onlyOnDetail(),

            DateTime::make('Requested At', 'created_at')->sortable()->exceptOnForms(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new Actions\ApproveEmployeeChange,
            new Actions\RejectEmployeeChange,
        ];
    }
}
