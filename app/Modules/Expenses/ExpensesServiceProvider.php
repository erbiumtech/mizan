<?php

namespace App\Modules\Expenses;

use App\Modules\Expenses\Filament\Pages\ExpenseClaimsReport;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Policies\ExpenseClaimPolicy;
use App\Modules\Expenses\Services\ExpenseClaimService;
use App\Modules\Expenses\Support\ExpenseReports;
use App\Support\Contracts\ReimbursableClaims;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantFileAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies are registered explicitly: Laravel's App\Models\X -> App\Policies\XPolicy
 * guess cannot resolve a model in a module directory, and Filament treats a model
 * with no policy as allowed.
 */
class ExpensesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ExpenseClaim::class => ExpenseClaimPolicy::class,
        \App\Modules\Expenses\Models\ExpenseCategory::class => \App\Modules\Expenses\Policies\ExpenseCategoryPolicy::class,
        \App\Modules\Expenses\Models\ExpenseClaimLine::class => \App\Modules\Expenses\Policies\ExpenseClaimLinePolicy::class,
        \App\Modules\Expenses\Models\ExpenseClaimApproval::class => \App\Modules\Expenses\Policies\ExpenseClaimApprovalPolicy::class,
    ];

    public function register(): void
    {
        // What an employee is owed back, for the payslip that reimburses it. Same inversion as Advances and
        // for the same reason: `expenses` requires `payroll`, so Payroll asking a contract is the only
        // direction that is not a cycle. See docs/module-packaging-plan.md §11.
        $this->app->bind(ReimbursableClaims::class, ExpenseClaimService::class);
    }

    public function boot(): void
    {
        // Claims waiting on a decision, for the Material dashboard's "Waiting for you" card.
        \App\Modules\Expenses\Dashboard\ExpensesDashboard::register();

        // The approval chain's switch — docs/saas-launch-plan.md §14.3. 63: after the payroll run's.
        \App\Support\SettingsSections::register('expenses.approval-chain', \App\Modules\Expenses\Filament\Settings\ExpenseApprovalSettingsSection::class, 63, 'people');

        $this->registerReports();

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // A claim receipt streams only to someone who may view the claim — security audit finding 10.
        TenantFileAccess::registerRecordPrefix('expense-claims/', fn (string $path) => ExpenseClaim::query()->where('receipt_path', $path)->first());

        // The approvals API — docs/desktop-mobile-plan.md A4/M3.
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
    }

    /**
     * The claims report — `docs/reports-expansion-plan.md` Phase 2.8.
     *
     * Filed under *People & payroll*: it is about what staff have spent and are owed, and it is read by
     * whoever approves claims or runs payroll.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without expenses sees no entry rather than a report that
     * fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            ExpenseClaimsReport::class,
            'Claims by state and person, and what is approved but not yet reimbursed.',
        );

        ReportRenderers::register(
            'ExpenseClaimsReport',
            fn (string $asOf): array => app(ExpenseReports::class)->claims($asOf),
        );
    }
}
