<?php

namespace App\Modules\Lifecycle;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Events\EmployeeJoined;
use App\Modules\Lifecycle\Console\Commands\CheckDocumentExpiry;
use App\Modules\Lifecycle\Filament\Pages\AssetsInHand;
use App\Modules\Lifecycle\Filament\Pages\ChecklistProgress;
use App\Modules\Lifecycle\Filament\Pages\DocumentsExpiring;
use App\Modules\Lifecycle\Filament\Pages\FinalSettlementsReport;
use App\Modules\Lifecycle\Filament\Pages\LeaveLiability;
use App\Modules\Lifecycle\Listeners\StartOnboardingForNewEmployee;
use App\Modules\Lifecycle\Models\ChecklistItem;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeChecklist;
use App\Modules\Lifecycle\Models\EmployeeChecklistItem;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Modules\Lifecycle\Policies\ChecklistItemPolicy;
use App\Modules\Lifecycle\Policies\ChecklistTemplatePolicy;
use App\Modules\Lifecycle\Policies\EmployeeChecklistItemPolicy;
use App\Modules\Lifecycle\Policies\EmployeeChecklistPolicy;
use App\Modules\Lifecycle\Policies\EmployeeDocumentPolicy;
use App\Modules\Lifecycle\Policies\FinalSettlementPolicy;
use App\Modules\Lifecycle\Policies\IssuedAssetPolicy;
use App\Modules\Lifecycle\Support\ChecklistReports;
use App\Modules\Lifecycle\Support\LifecycleReports;
use App\Modules\Lifecycle\Support\SettlementReports;
use App\Support\EmployeeAccess;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantFileAccess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Policies explicitly: a model in a module directory never resolves by Laravel's guess. */
class LifecycleServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ChecklistTemplate::class => ChecklistTemplatePolicy::class,
        ChecklistItem::class => ChecklistItemPolicy::class,
        EmployeeChecklist::class => EmployeeChecklistPolicy::class,
        EmployeeChecklistItem::class => EmployeeChecklistItemPolicy::class,
        EmployeeDocument::class => EmployeeDocumentPolicy::class,
        IssuedAsset::class => IssuedAssetPolicy::class,
        FinalSettlement::class => FinalSettlementPolicy::class,
        \App\Modules\Lifecycle\Models\EmploymentContract::class => \App\Modules\Lifecycle\Policies\EmploymentContractPolicy::class,
        \App\Modules\Lifecycle\Models\Separation::class => \App\Modules\Lifecycle\Policies\SeparationPolicy::class,
        \App\Modules\Lifecycle\Models\ClearanceItem::class => \App\Modules\Lifecycle\Policies\ClearanceItemPolicy::class,
    ];

    public function register(): void
    {
        // A leaver's login window — docs/saas-launch-plan.md §14.5. Core asks the contract.
        $this->app->bind(\App\Support\Contracts\LoginWindow::class, \App\Modules\Lifecycle\Services\SeparationLoginWindow::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Hire starts the onboarding checklist — plan §7.3. Recruitment announces the
        // joiner; this module decides what that means, exactly as Employees answers
        // Core's UserCreated.
        Event::listen(EmployeeJoined::class, StartOnboardingForNewEmployee::class);

        // employee-documents/ files stream only to the employee's own reporting line — security audit finding 10.
        TenantFileAccess::register(function (User $user, Company $company, string $path): ?bool {
            if (! str_starts_with($path, 'employee-documents/')) {
                return null;
            }

            $lookup = fn () => EmployeeDocument::query()->where('file_path', $path)->first();
            $switch = ! Company::current()?->is($company) && filled(config('multitenancy.tenant_database_connection_name'));
            $record = $switch ? $company->execute($lookup) : $lookup();

            if (! $record) {
                return null;
            }

            $access = app(EmployeeAccess::class);

            return $access->isPrivileged($user) || $access->accessibleEmployeeIds($user)->contains($record->employee_id);
        });

        $this->commands([CheckDocumentExpiry::class, \App\Modules\Lifecycle\Console\Commands\RunLifecycleChecks::class]);

        // The contract's QR — docs/saas-launch-plan.md §14.5 — answers against the live record, as every document kind does.
        \App\Support\DocumentVerification::register(\App\Modules\Lifecycle\Services\EmploymentContracts::KIND, fn (int $id): ?array => ($c = \App\Modules\Lifecycle\Models\EmploymentContract::query()->with('employee.user')->find($id))
            ? app(\App\Modules\Lifecycle\Services\EmploymentContracts::class)->verificationFacts($c)
            : null);

        $this->registerReports();
    }

    /**
     * The expiring-documents report — `docs/reports-expansion-plan.md` Phase 1.5.
     *
     * Filed under *People & payroll* beside the timesheet pair: it is a list of people's documents, and
     * the person opening it is whoever is responsible for the paperwork.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without the lifecycle module sees no entry rather than a
     * report that fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            DocumentsExpiring::class,
            'Visas, licences and contracts lapsing soon, and the ones that already have.',
        );

        ReportCatalogue::register(
            'People & payroll',
            LeaveLiability::class,
            'What unused encashable leave would cost — the accrual that is in no account.',
        );

        ReportCatalogue::register(
            'People & payroll',
            AssetsInHand::class,
            'Laptops, phones and vehicles issued and not returned — and who has left holding one.',
        );

        ReportCatalogue::register(
            'People & payroll',
            FinalSettlementsReport::class,
            'What each leaver was owed and what it was made of — including the ones nobody built.',
        );

        ReportCatalogue::register(
            'People & payroll',
            ChecklistProgress::class,
            'Onboarding and exit tasks still outstanding, and whose queue they are sitting in.',
        );

        ReportRenderers::register(
            'DocumentsExpiring',
            fn (string $asOf): array => app(LifecycleReports::class)->documentsExpiring($asOf),
        );
        ReportRenderers::register(
            'LeaveLiability',
            fn (string $asOf): array => app(LifecycleReports::class)->leaveLiability($asOf),
        );
        ReportRenderers::register(
            'AssetsInHand',
            fn (string $asOf): array => app(LifecycleReports::class)->assetsInHand($asOf),
        );
        ReportRenderers::register(
            'FinalSettlementsReport',
            fn (string $asOf): array => app(SettlementReports::class)->settlements($asOf),
        );
        ReportRenderers::register(
            'ChecklistProgress',
            fn (string $asOf): array => app(ChecklistReports::class)->checklistProgress($asOf),
        );
    }
}
