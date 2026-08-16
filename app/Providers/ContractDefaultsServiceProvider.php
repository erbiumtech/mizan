<?php

namespace App\Providers;

use App\Support\Contracts\ConfiguredWeekendCalendar;
use App\Support\Contracts\NeverLocked;
use App\Support\Contracts\PeriodLock;
use App\Support\Contracts\WorkingDayCalendar;
use Illuminate\Support\ServiceProvider;

/**
 * Default answers to questions a module answers properly when it is installed.
 *
 * Shared code and other modules ask a contract instead of naming the module that
 * knows — which is what breaks the import cycles that stop either side becoming a
 * composer package (docs/module-packaging-plan.md §6).
 *
 * Every default here is not a stub: it is the behaviour a company *without* that
 * module already had, moved out of an `if (modules()->enabled(...))` at the call
 * site and into a binding. So removing a module changes nothing that was not
 * already true for a company that never licensed it.
 *
 * **Registered before every module provider, and that ordering is load-bearing.**
 * A module rebinds a contract in its own `register()`, and the last binding wins.
 * These defaults sat in AppServiceProvider first, which is listed *after* the
 * module providers in bootstrap/providers.php — so the default overwrote
 * Attendance's real calendar and every leave request silently fell back to the
 * configured weekend. The six-day-pattern test caught it; nothing else would have.
 */
class ContractDefaultsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WorkingDayCalendar::class, ConfiguredWeekendCalendar::class);
        $this->app->bind(PeriodLock::class, NeverLocked::class);
    }
}
