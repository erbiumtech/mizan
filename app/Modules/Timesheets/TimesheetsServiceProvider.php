<?php

namespace App\Modules\Timesheets;

use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Policies\TimesheetEntryPolicy;
use App\Modules\Timesheets\Services\BillableHours;
use App\Support\Contracts\BillableTime;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Policies explicitly: a model in a module directory never resolves by Laravel's guess. */
class TimesheetsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        TimesheetEntry::class => TimesheetEntryPolicy::class,
    ];

    public function register(): void
    {
        // Booked time, priced, for Billing — which asks the contract rather than naming this module, because
        // neither of the two requires the other and the pair was a cycle anyway. Replaces the null default
        // bound in ContractDefaultsServiceProvider; the licence guard is inside BillableHours, so binding
        // unconditionally is correct. See docs/module-packaging-plan.md §11.
        $this->app->bind(BillableTime::class, BillableHours::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
