<?php

namespace App\Modules\Timesheets;

use App\Modules\Timesheets\Filament\Pages\PlanVersusActual;
use App\Modules\Timesheets\Filament\Pages\TimesheetUtilisation;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Policies\TimesheetEntryPolicy;
use App\Modules\Timesheets\Services\BillableHours;
use App\Modules\Timesheets\Support\TimesheetReports;
use App\Support\Contracts\BillableTime;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
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

        $this->registerReports();
    }

    /**
     * The utilisation report and the plan-versus-actual matrix — `docs/reports-expansion-plan.md` Phase 1.4.
     *
     * Filed under *People & payroll*: both are read about the team rather than about the projects, and the
     * person asking is a manager looking at their people. Phase 2's payroll register and leave liability
     * join them there.
     *
     * Registered unconditionally. Each page gates itself on `moduleIsAvailable()` and
     * `Reports::sections()` filters through `canAccess()`, so a company without timesheets sees no heading
     * rather than two reports that fail when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            TimesheetUtilisation::class,
            'Hours booked per person for a month, billable against non-billable.',
        );
        ReportCatalogue::register(
            'People & payroll',
            PlanVersusActual::class,
            'What each person was allocated to, against the hours they actually booked.',
        );

        ReportRenderers::register('TimesheetUtilisation', fn (string $asOf): array => app(TimesheetReports::class)->utilisation($asOf));
        ReportRenderers::register('PlanVersusActual', fn (string $asOf): array => app(TimesheetReports::class)->planVersusActual($asOf));
    }
}
