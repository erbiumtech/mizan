<?php

namespace App\Modules\Employees;

use App\Events\UserCreated;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Console\Commands\ApplyDueJobChanges;
use App\Modules\Employees\Filament\Pages\HeadcountMovement;
use App\Modules\Employees\Listeners\CreateEmployeeForUser;
use App\Modules\Employees\Models\Announcement;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Employees\Policies\AnnouncementPolicy;
use App\Modules\Employees\Policies\EmployeeChangeRequestPolicy;
use App\Modules\Employees\Policies\EmployeePolicy;
use App\Modules\Employees\Policies\EmployeeSettingPolicy;
use App\Modules\Employees\Services\AppointmentLetter;
use App\Modules\Employees\Services\ExperienceLetter;
use App\Modules\Employees\Services\IncomeCertificate;
use App\Modules\Employees\Support\HeadcountReports;
use App\Support\CustomFieldSubjects;
use App\Support\DashboardStats;
use App\Support\DocumentVerification;
use App\Support\EmployeeAccess;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantFileAccess;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Employees module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class EmployeesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Announcement::class => AnnouncementPolicy::class,
        EmployeeChangeRequest::class => EmployeeChangeRequestPolicy::class,
        Employee::class => EmployeePolicy::class,
        EmployeeSetting::class => EmployeeSettingPolicy::class,
        \App\Modules\Employees\Models\LetterTemplate::class => \App\Modules\Employees\Policies\LetterTemplatePolicy::class,
        \App\Modules\Employees\Models\LetterRequest::class => \App\Modules\Employees\Policies\LetterRequestPolicy::class,
        \App\Modules\Employees\Models\Department::class => \App\Modules\Employees\Policies\DepartmentPolicy::class,
        \App\Modules\Employees\Models\Designation::class => \App\Modules\Employees\Policies\DesignationPolicy::class,
        \App\Modules\Employees\Models\EmployeeVerification::class => \App\Modules\Employees\Policies\EmployeeVerificationPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerReports();

        // Employees from a spreadsheet — docs/saas-launch-plan.md §14.2.
        \App\Support\CsvImporters::register('employees', \App\Modules\Employees\Support\EmployeeCsvImporter::class, 15);

        // The records of this module that may carry custom fields. Registered by alias, which is what
        // `custom_fields.model_type` stores — see App\Support\CustomFieldSubjects.
        CustomFieldSubjects::register(ModuleMap::alias(Employee::class), 'Employees');

        // A new user gets an employee record. Registered here rather than done in Core's CreateUser page.
        Event::listen(UserCreated::class, CreateEmployeeForUser::class);

        DashboardStats::register('employees.active', fn () => auth()->user()?->can('EmployeeView')
            ? Stat::make('Employees', Employee::where('is_active', 1)->count())->description('active')
            : null, sort: 10);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // The checks a company may insist on before somebody is let in — plan §14.8.
        \App\Modules\Employees\Support\EmployeeChecks::register();

        /*
         * What an integration can hear about people — plan §14.9.
         *
         * The payload names its fields rather than handing over the row. It is a public
         * contract with somebody else's system, and `toArray()` would change it the day
         * a column is added — including the day the column is a bank account.
         */
        \App\Support\Webhooks::register(
            'employee.joined',
            'Somebody joined',
            \App\Modules\Employees\Events\EmployeeJoined::class,
            fn (object $event): array => [
                'id' => $event->employee->getKey(),
                'code' => $event->employee->employee_id,
                'name' => $event->employee->fullName(),
                'designation' => $event->employee->designation,
                'department' => $event->employee->department,
                'joined_on' => $event->employee->date_of_joining?->toDateString(),
            ],
            sort: 20,
        );

        \App\Support\Webhooks::register(
            'employee.activated',
            'An employee was activated',
            \App\Modules\Employees\Events\EmployeeActivated::class,
            function (object $event): array {
                $employee = Employee::query()->with('user')->find($event->employeeId);

                return [
                    'id' => $event->employeeId,
                    'code' => $employee?->employee_id,
                    'name' => $employee?->fullName(),
                ];
            },
            sort: 30,
        );

        /*
         * This module's slices of a subject-access disclosure — plan §14.8.
         *
         * The record itself, what they asked to have changed about it, and what has been
         * confirmed. The scan *paths* are disclosed rather than the scans: a JSON file
         * with two megabytes of base64 identity card in it is not a more complete answer,
         * and the files are reachable to the person through the panel.
         */
        \App\Support\SubjectAccess::register('The employee record', function (object $employee): array {
            $record = $employee->toArray();
            $record['name_on_account'] = $employee->user?->name;
            $record['email_on_account'] = $employee->user?->email;

            return $record;
        }, sort: 10);

        \App\Support\SubjectAccess::register('Changes you asked for', fn (object $employee): array => \App\Modules\Employees\Models\EmployeeChangeRequest::query()
            ->where('employee_id', $employee->getKey())
            ->get(['id', 'status', 'requested_changes', 'original_values', 'created_at'])
            ->toArray(), sort: 20);

        \App\Support\SubjectAccess::register('What you have confirmed', fn (object $employee): array => \App\Modules\Employees\Models\EmployeeVerification::query()
            ->where('employee_id', $employee->getKey())
            ->get(['kind', 'target', 'verified_at'])
            ->toArray(), sort: 30);

        \App\Support\SubjectAccess::register('Letters you asked for', fn (object $employee): array => \App\Modules\Employees\Models\LetterRequest::query()
            ->where('employee_id', $employee->getKey())
            ->get(['kind', 'purpose', 'status', 'refusal_reason', 'created_at'])
            ->toArray(), sort: 40);

        // The letters' verification codes — plan §7.5. Each asked with the company current.
        $employee = fn (int $id): ?Employee => Employee::query()->with('user')->find($id);
        DocumentVerification::register('income-certificate', fn (int $id): ?array => ($e = $employee($id)) ? app(IncomeCertificate::class)->verificationFacts($e) : null);
        DocumentVerification::register('experience-letter', fn (int $id): ?array => ($e = $employee($id)) ? app(ExperienceLetter::class)->verificationFacts($e) : null);
        DocumentVerification::register('appointment-letter', fn (int $id): ?array => ($e = $employee($id)) ? app(AppointmentLetter::class)->verificationFacts($e) : null);

        // NIC scans stream only to the employee's own reporting line — security audit finding 10.
        TenantFileAccess::register(function (User $user, Company $company, string $path): ?bool {
            if (! str_starts_with($path, 'nic/')) {
                return null;
            }

            $lookup = fn () => Employee::query()->where('nic_front', $path)->orWhere('nic_back', $path)->first();
            $switch = ! Company::current()?->is($company) && filled(config('multitenancy.tenant_database_connection_name'));
            $employee = $switch ? $company->execute($lookup) : $lookup();

            if (! $employee) {
                return null;
            }

            $access = app(EmployeeAccess::class);

            return $access->isPrivileged($user) || $access->accessibleEmployeeIds($user)->contains($employee->id);
        });

        $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        // Laravel auto-discovers commands only in app/Console/Commands, so one
        // living in a module has to be registered here or it exists and cannot
        // be run — including by the schedule entry that names it.
        $this->commands([
            ApplyDueJobChanges::class,
            \App\Modules\Employees\Console\Commands\PublishDueAnnouncements::class,
        ]);
    }

    /**
     * The headcount report — `docs/reports-expansion-plan.md` Phase 3.6.
     *
     * Filed under *People & payroll* with the register and the liabilities: it is the report somebody opens
     * to ask how the workforce moved, and it reads against the payroll register for the same months.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            HeadcountMovement::class,
            'Joiners and leavers per month, headcount, turnover, and how long the leavers stayed.',
        );

        ReportCatalogue::register(
            'People & payroll',
            \App\Modules\Employees\Filament\Pages\Anniversaries::class,
            'Who has a work anniversary coming, and which one — counted on continuous service, not the joining date.',
        );

        ReportCatalogue::register(
            'People & payroll',
            \App\Modules\Employees\Filament\Pages\Demographics::class,
            'Who the company is: gender, age, marital status, department and employment type, and what nobody recorded.',
        );

        ReportRenderers::register(
            'HeadcountMovement',
            fn (string $asOf): array => app(HeadcountReports::class)->movement($asOf),
        );
        ReportRenderers::register(
            'Anniversaries',
            fn (string $asOf): array => app(HeadcountReports::class)->anniversaries($asOf),
        );
        ReportRenderers::register(
            'Demographics',
            fn (string $asOf): array => app(HeadcountReports::class)->demographics($asOf),
        );
    }
}
