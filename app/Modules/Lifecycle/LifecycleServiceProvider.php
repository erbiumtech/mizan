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
use App\Modules\Lifecycle\Services\ChecklistService;
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

        /*
         * What the lifecycle module does about the law — docs/saas-launch-plan.md §14.10.
         */
        \App\Support\StatutoryCoverage::register(
            'Standing Orders Ordinance 1968 — gratuity and final settlement',
            'Pakistan (federal)',
            'Builds a final settlement on separation: gratuity from the service the records show, leave '
            .'encashment at the rate the leave type allows, and whatever is still outstanding against the '
            .'person — shown as a preview before anything is paid.',
            'lifecycle',
            'Separations · Reports → Final Settlements',
        );
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

        // What a company insists on, and what it will accept — plan §14.8.
        \App\Support\SettingsSections::register('lifecycle.documents', \App\Modules\Lifecycle\Filament\Settings\RequiredDocumentsSettingsSection::class, 62, 'people');

        // Which onboarding template a joiner gets when several are active — plan §14.5.
        \App\Support\SettingsSections::register('lifecycle.onboarding', \App\Modules\Lifecycle\Filament\Settings\OnboardingSettingsSection::class, 63, 'people');

        // "Start checklist" on the employee — for somebody who joined before the template existed, a
        // second template, or an exit list. Contributed: Employees may not name this module.
        \App\Support\ResourceContributions::addHeaderAction(\App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee::class, 'startChecklist', fn () => $this->startChecklistAction());

        /*
         * Documents as a condition of being let in — plan §14.8.
         *
         * `available` is whether the company has named any required kind at all: a check
         * with nothing on its list cannot be failed, so offering it would be a switch that
         * does nothing. That is the same rule the phone and address checks follow.
         */
        \App\Support\VerificationChecks::register(
            key: 'lifecycle.documents',
            label: 'The required documents are on file',
            help: 'The kinds ticked under Employee documents, each held and not refused or lapsed.',
            available: fn (): bool => \App\Modules\Lifecycle\Support\DocumentRequirements::required() !== [],
            outstanding: function (?object $user): ?string {
                $employee = \App\Modules\Employees\Support\EmployeeChecks::employeeFor($user);

                if ($employee === null) {
                    return null;
                }

                $missing = \App\Modules\Lifecycle\Support\DocumentRequirements::missingFor($employee);

                return $missing === [] ? null : 'Still to send: '.implode(', ', $missing).'.';
            },
            sort: 40,
        );

        // This module's slices of a subject-access disclosure — plan §14.8. Paths, never
        // the files: the scans are reachable to the person through the panel, and a JSON
        // file full of base64 is not a fuller answer.
        \App\Support\SubjectAccess::register('Documents on file', fn (object $employee): array => \App\Modules\Lifecycle\Models\EmployeeDocument::query()
            ->where('employee_id', $employee->getKey())
            ->get(['kind', 'number', 'issued_on', 'expires_on', 'file_path', 'verified_at', 'rejected_at', 'rejection_reason'])
            ->toArray(), sort: 50);

        \App\Support\SubjectAccess::register('Company property issued to you', fn (object $employee): array => \App\Modules\Lifecycle\Models\IssuedAsset::query()
            ->where('employee_id', $employee->getKey())
            ->get(['asset_kind', 'description', 'serial_no', 'issued_on', 'returned_on'])
            ->toArray(), sort: 60);

        $this->registerReports();
    }

    private function startChecklistAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('startChecklist')
            ->label('Start checklist')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->visible(fn (): bool => modules()->enabled('lifecycle') && (auth()->user()?->can('create', EmployeeChecklist::class) ?? false))
            ->modalDescription('Starts the template\'s items for this person, each due its number of days from the joining date (onboarding) or the last working day (exit).')
            ->schema([
                \Filament\Forms\Components\Select::make('template_id')
                    ->label('Checklist')
                    ->options(fn (): array => ChecklistTemplate::query()->active()->orderBy('kind')->orderBy('name')->get()
                        ->mapWithKeys(fn (ChecklistTemplate $t): array => [$t->getKey() => $t->name.' ('.ucfirst($t->kind).')'])->all())
                    ->default(fn (): ?int => app(ChecklistService::class)->defaultTemplate(ChecklistTemplate::KIND_ONBOARDING)?->getKey())
                    ->required(),
            ])
            ->action(function (\App\Modules\Employees\Models\Employee $record, array $data): void {
                $template = ChecklistTemplate::query()->active()->with('items')->findOrFail($data['template_id']);

                try {
                    $checklist = app(ChecklistService::class)->start($record, $template);
                } catch (\InvalidArgumentException $e) {
                    \Filament\Notifications\Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                \Filament\Notifications\Notification::make()->success()
                    ->title($template->name.' started')
                    ->body($checklist->items()->count().' item(s), on Checklist Items.')
                    ->send();
            });
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
            \App\Modules\Lifecycle\Filament\Pages\DocumentCompliance::class,
            'Who is short of a document the company requires — the ones that were never sent have no row anywhere else.',
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
            'DocumentCompliance',
            fn (string $asOf): array => app(LifecycleReports::class)->documentCompliance($asOf),
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
