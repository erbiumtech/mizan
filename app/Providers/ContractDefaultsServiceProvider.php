<?php

namespace App\Providers;

use App\Support\Contracts\AdvanceLedger;
use App\Support\Contracts\BillableTime;
use App\Support\Contracts\ConfiguredWeekendCalendar;
use App\Support\Contracts\FiscalYearCloseCheck;
use App\Support\Contracts\NeverLocked;
use App\Support\Contracts\NoAdvanceLedger;
use App\Support\Contracts\NoBillableTime;
use App\Support\Contracts\NoFiscalYearClose;
use App\Support\Contracts\NoReimbursableClaims;
use App\Support\Contracts\PeriodLock;
use App\Support\Contracts\ReimbursableClaims;
use App\Support\Contracts\WorkingDayCalendar;
use App\Support\Reporting\NoReportPane;
use App\Support\Reporting\ReportPaneRenderer;
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
        // Closing a fiscal year is an accounting act on a Core model. With no accounting module there is
        // nothing that can do it, and the default says so rather than silently doing nothing.
        $this->app->bind(FiscalYearCloseCheck::class, NoFiscalYearClose::class);

        // With no accounting module there is no pane, and every report in the hub opens on its own page.
        $this->app->bind(ReportPaneRenderer::class, NoReportPane::class);

        // With no timesheets module nothing is billed by the hour, which is what a headcount-billed client's
        // invoice already looked like.
        $this->app->bind(BillableTime::class, NoBillableTime::class);

        // A payslip deducts an advance instalment and reimburses expense claims, and both ledgers live in
        // modules that *require* Payroll — so Payroll asks rather than names. Nothing owed and nothing to
        // record is the honest answer for a company that bought neither, and it is the behaviour payroll's
        // own `modules()->enabled()` guards produced before these became contracts.
        $this->app->bind(AdvanceLedger::class, NoAdvanceLedger::class);
        $this->app->bind(ReimbursableClaims::class, NoReimbursableClaims::class);

        $this->app->bind(WorkingDayCalendar::class, ConfiguredWeekendCalendar::class);
        $this->app->bind(PeriodLock::class, NeverLocked::class);
    }
}
