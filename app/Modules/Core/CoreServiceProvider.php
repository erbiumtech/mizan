<?php

namespace App\Modules\Core;

use App\Modules\Core\Console\Commands\DeliverScheduledReports;
use App\Modules\Core\Filament\Pages\ReportDeliveries;
use App\Modules\Core\Filament\Settings\DocumentLayoutSettingsSection;
use App\Modules\Core\Filament\Settings\DocumentVerificationSettingsSection;
use App\Modules\Core\Listeners\LogAuthenticationEvent;
use App\Modules\Core\Listeners\LogSentMail;
use App\Modules\Core\Listeners\NotifyRecordAudience;
use App\Modules\Core\Listeners\RespectNotificationPreferences;
use App\Modules\Core\Listeners\SkipBroadcastWithoutBroadcaster;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\MessageLog;
use App\Modules\Core\Models\OptionValue;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Core\Models\ReportSchedule;
use App\Modules\Core\Models\TableView;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WorkLocation;
use App\Modules\Core\Policies\ActivityLogPolicy;
use App\Modules\Core\Policies\CommentPolicy;
use App\Modules\Core\Policies\CompanyPolicy;
use App\Modules\Core\Policies\CustomFieldPolicy;
use App\Modules\Core\Policies\EmailTemplatePolicy;
use App\Modules\Core\Policies\FiscalYearPolicy;
use App\Modules\Core\Policies\HolidayPolicy;
use App\Modules\Core\Policies\MessageLogPolicy;
use App\Modules\Core\Policies\OptionValuePolicy;
use App\Modules\Core\Policies\PermissionPolicy;
use App\Modules\Core\Policies\ReportSchedulePolicy;
use App\Modules\Core\Policies\RolePolicy;
use App\Modules\Core\Policies\TableViewPolicy;
use App\Modules\Core\Policies\UserPolicy;
use App\Modules\Core\Policies\WorkLocationPolicy;
use App\Modules\Core\Services\HolidayCalendar;
use App\Support\Reporting\BuiltReport;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportDeliveryLog;
use App\Support\Reporting\ReportRenderers;
use App\Support\SettingsSections;
use App\Support\TenantExport;
use App\Support\TenantFileAccess;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Everything the Core module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class CoreServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ActivityLog::class => ActivityLogPolicy::class,
        Comment::class => CommentPolicy::class,
        Company::class => CompanyPolicy::class,
        CustomField::class => CustomFieldPolicy::class,
        FiscalYear::class => FiscalYearPolicy::class,
        Holiday::class => HolidayPolicy::class,
        MessageLog::class => MessageLogPolicy::class,
        WorkLocation::class => WorkLocationPolicy::class,
        OptionValue::class => OptionValuePolicy::class,
        ReportSchedule::class => ReportSchedulePolicy::class,
        TableView::class => TableViewPolicy::class,
        EmailTemplate::class => EmailTemplatePolicy::class,
        User::class => UserPolicy::class,

        // Vendor models Core owns the authorization for. The activity log is
        // spatie's Activity, not our ActivityLog wrapper, so it needs naming
        // separately or audit rows authorize as unpoliced.
        Activity::class => ActivityLogPolicy::class,
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
    ];

    public function register(): void
    {
        // A singleton because its cache is only worth having if it is shared:
        // the leave-day generator resolves it once and loops, but attendance and
        // any validation in the same request resolve it again, and a fresh
        // instance each time reads the whole table each time.
        $this->app->singleton(HolidayCalendar::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerBuiltReports();
        $this->registerDeliveryLog();

        // The signup door — docs/saas-launch-plan.md §5. Core's, because a company
        // and its first user are Core's; loaded the way every module loads its own.
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        // A company's data export (plan §9) streams only to its Administrators. The
        // role is per company, and the file route sets no team, so it is asked
        // against the company in the URL and the previous team is put back.
        TenantFileAccess::register(function (User $user, Company $company, string $path): ?bool {
            if (! str_starts_with($path, TenantExport::PREFIX)) {
                return null;
            }

            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($company->getKey());

            try {
                return $user->isAdministrator();
            } finally {
                $registrar->setPermissionsTeamId($previous);
            }
        });

        /*
         * The bell's general case: an audited change tells the people whose record it is.
         *
         * Hung off the activity row rather than off 157 models, because the audit trail is already the
         * answer to "what changed, to what, by whom" — see NotifyRecordAudience. `created` on the model
         * Spatie is configured to write through (config/activitylog.php) is every audited change in the
         * application, in one place.
         */
        ActivityLog::created(fn (ActivityLog $activity) => app(NotifyRecordAudience::class)($activity));

        // With no broadcaster configured, the broadcast channel is withdrawn from every notification before
        // it becomes a job that can only fail. See the listener.
        Event::listen(NotificationSending::class, SkipBroadcastWithoutBroadcaster::class);

        // Email a person has asked not to receive — docs/saas-launch-plan.md §14.7. Mail only; the bell
        // keeps showing everything.
        Event::listen(NotificationSending::class, RespectNotificationPreferences::class);

        // Every mail in the message log, the envelope and never the body.
        Event::listen(MessageSent::class, LogSentMail::class);

        // Document verification — plan §7.5: the two gates on Company Settings.
        SettingsSections::register('documents.verification', DocumentVerificationSettingsSection::class, 85);

        // How documents are laid out — plan §14.7. Beside verification: both are about
        // the paper rather than the figures on it.
        SettingsSections::register('documents.layout', DocumentLayoutSettingsSection::class, 86);

        // The sign-in log — plan §7.7: three rows on the landlord activity log, no table.
        Event::listen([Login::class, Logout::class, Failed::class], LogAuthenticationEvent::class);

        // Registered as well as scheduled: `Schedule::command()` in routes/console.php only wires the
        // timetable, and a command nobody can invoke by hand is a command nobody can test or re-run after a
        // failed night.
        $this->commands([DeliverScheduledReports::class, \App\Modules\Core\Console\Commands\RolloverHolidays::class]);

        // A year of holidays from a spreadsheet — docs/saas-launch-plan.md §14.4.
        \App\Support\CsvImporters::register('holidays', \App\Modules\Core\Support\HolidayCsvImporter::class, 35);

    }

    /**
     * Reports somebody assembled — `docs/reports-expansion-plan.md` Phase 6, item 4.
     *
     * A *family* rather than a renderer per report, because these keys are rows of `report_definitions` and
     * there is nothing to enumerate when a provider boots. Registered by Core because Core owns
     * `ReportDefinition`, exactly as each module registers the reports it owns — and Core is the module that
     * is always on, which is what makes a custom report available to a company that has bought nothing else.
     *
     * The pane, `NoReportPane` and every report page already ask `ReportRenderers`, so this one line is what
     * puts a built report on all three.
     */
    private function registerBuiltReports(): void
    {
        ReportRenderers::registerFamily(
            ReportDefinition::KEY_PREFIX,
            fn (string $key, string $asOf): ?array => app(BuiltReport::class)->forKey($key, $asOf),
        );
    }

    /**
     * The delivery log — `docs/reports-expansion-plan.md` Phase 8, item 8.
     *
     * A report like any other: registered into the hub, rendered by the module that owns it, drawn in the pane
     * and exported by Phase 4. Filed under *Operations* rather than with the financial statements, because
     * what it answers is "did the application do what it was told" and the person asking that is not reading
     * about money.
     */
    private function registerDeliveryLog(): void
    {
        ReportCatalogue::register(
            'Operations',
            ReportDeliveries::class,
            'Every scheduled report that went out, who it reached, and what failed.',
        );

        ReportRenderers::register(
            'ReportDeliveries',
            fn (string $asOf): array => app(ReportDeliveryLog::class)->for($asOf),
        );
    }
}
