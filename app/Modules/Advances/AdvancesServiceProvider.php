<?php

namespace App\Modules\Advances;

use App\Modules\Advances\Filament\Pages\AdvancesOutstanding;
use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Policies\AdvancePolicy;
use App\Modules\Advances\Services\AdvanceService;
use App\Modules\Advances\Support\AdvanceReports;
use App\Support\Contracts\AdvanceLedger;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies are registered explicitly: Laravel's App\Models\X -> App\Policies\XPolicy
 * guess cannot resolve a model in a module directory, and Filament treats a model
 * with no policy as allowed.
 */
class AdvancesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Advance::class => AdvancePolicy::class,
    ];

    public function register(): void
    {
        // What an employee owes on an advance, for the payslip that deducts it. Payroll asks the contract
        // rather than naming this module — `advances` requires `payroll`, so that was a cycle. Replaces the
        // null default in ContractDefaultsServiceProvider; the licence guard is inside AdvanceService, so
        // binding unconditionally is correct. See docs/module-packaging-plan.md §11.
        $this->app->bind(AdvanceLedger::class, AdvanceService::class);
    }

    public function boot(): void
    {
        $this->registerReports();

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * The outstanding-advances report — `docs/reports-expansion-plan.md` Phase 2.7.
     *
     * Filed under *People & payroll*: it is a figure about staff, read by whoever runs payroll, even though
     * it is a receivable the accounts carry.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without advances sees no entry rather than a report that
     * fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            AdvancesOutstanding::class,
            'What staff owe the company, the instalment recovering it, and whether the accounts agree.',
        );

        ReportRenderers::register(
            'AdvancesOutstanding',
            fn (string $asOf): array => app(AdvanceReports::class)->outstanding($asOf),
        );
    }
}
