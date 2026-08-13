<?php

namespace App\Modules\Timesheets;

use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Policies\TimesheetEntryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Policies explicitly: a model in a module directory never resolves by Laravel's guess. */
class TimesheetsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        TimesheetEntry::class => TimesheetEntryPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
