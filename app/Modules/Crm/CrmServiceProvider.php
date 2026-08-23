<?php

namespace App\Modules\Crm;

use App\Modules\Crm\Filament\Pages\PipelineByStage;
use App\Modules\Crm\Filament\Pages\RottingDeals;
use App\Modules\Crm\Filament\Pages\SalesForecast;
use App\Modules\Crm\Filament\Pages\TargetAttainment;
use App\Modules\Crm\Filament\Pages\WinLoss;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Crm\Models\NextAction;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\OpportunityStageHistory;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Models\SalesTarget;
use App\Modules\Crm\Policies\ActivityPolicy;
use App\Modules\Crm\Policies\LeadPolicy;
use App\Modules\Crm\Policies\LeadSourcePolicy;
use App\Modules\Crm\Policies\LostReasonPolicy;
use App\Modules\Crm\Policies\NextActionPolicy;
use App\Modules\Crm\Policies\OpportunityPolicy;
use App\Modules\Crm\Policies\OpportunityStageHistoryPolicy;
use App\Modules\Crm\Policies\PipelinePolicy;
use App\Modules\Crm\Policies\PipelineStagePolicy;
use App\Modules\Crm\Policies\SalesTargetPolicy;
use App\Modules\Crm\Support\CrmReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the CRM module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X -> App\Policies\XPolicy,
 * which cannot resolve a model in a module directory, and Filament treats a model with no
 * policy as allowed — so without this map every resource here would be open to any
 * authenticated user.
 */
class CrmServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Lead::class => LeadPolicy::class,
        LeadSource::class => LeadSourcePolicy::class,
        Pipeline::class => PipelinePolicy::class,
        PipelineStage::class => PipelineStagePolicy::class,
        LostReason::class => LostReasonPolicy::class,
        Opportunity::class => OpportunityPolicy::class,
        OpportunityStageHistory::class => OpportunityStageHistoryPolicy::class,
        Activity::class => ActivityPolicy::class,
        NextAction::class => NextActionPolicy::class,
        SalesTarget::class => SalesTargetPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerReports();

        // The public lead-capture endpoint. Outside the panel, so the panel's tenancy
        // middleware never runs for it — see ResolveLeadCaptureTenant.
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }

    /**
     * The five pipeline reports — `docs/reports-expansion-plan.md` Phase 1.2.
     *
     * Two registrations each and they answer different questions. `ReportCatalogue` is what the hub *lists*
     * — the page, its section and the sentence describing what it answers. `ReportRenderers` is what draws
     * it, in the pane and on its own page both. Split because a report can be listed by a module and drawn
     * by nobody (the Accounting reports the pane draws itself) or drawn without being listed, and the two
     * failures look nothing alike: `ReportsHubTest` catches the first, a `RuntimeException` from
     * `ModuleReportPage::statement()` the second.
     *
     * Registered unconditionally, whatever the company has licensed. Each page gates itself on
     * `moduleIsAvailable()` and `Reports::sections()` filters through `canAccess()`, so a company without
     * CRM sees no *Sales & pipeline* section at all rather than five reports that fail when opened — see
     * `CrmPlugin`, which registers with the panel on the same terms and for the same reason.
     *
     * The descriptions are the questions, not the titles restated. That is what makes the hub searchable by
     * what somebody wants to know — "stopped moving" finds the rotting deals without their name.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'Sales & pipeline',
            PipelineByStage::class,
            'What is in the pipeline right now, by stage, weighted and plain.',
        );
        ReportCatalogue::register(
            'Sales & pipeline',
            SalesForecast::class,
            'What is expected to close this month, at each deal\'s own probability.',
        );
        ReportCatalogue::register(
            'Sales & pipeline',
            WinLoss::class,
            'Won against lost for the year, by source and owner, and why the losses were lost.',
        );
        ReportCatalogue::register(
            'Sales & pipeline',
            RottingDeals::class,
            'Open deals that have stopped moving or have nothing planned against them.',
        );
        ReportCatalogue::register(
            'Sales & pipeline',
            TargetAttainment::class,
            'How each salesperson is doing against the target covering this date.',
        );

        // A closure per report rather than one taking a method name: the signature is the contract, and a
        // string method name would let a typo register successfully and fail when somebody opens the report.
        ReportRenderers::register('PipelineByStage', fn (string $asOf): array => app(CrmReports::class)->byStage($asOf));
        ReportRenderers::register('SalesForecast', fn (string $asOf): array => app(CrmReports::class)->forecast($asOf));
        ReportRenderers::register('WinLoss', fn (string $asOf): array => app(CrmReports::class)->winLoss($asOf));
        ReportRenderers::register('RottingDeals', fn (string $asOf): array => app(CrmReports::class)->rotting($asOf));
        ReportRenderers::register('TargetAttainment', fn (string $asOf): array => app(CrmReports::class)->attainment($asOf));
    }
}
