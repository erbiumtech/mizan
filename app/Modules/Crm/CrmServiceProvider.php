<?php

namespace App\Modules\Crm;

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

        // The public lead-capture endpoint. Outside the panel, so the panel's tenancy
        // middleware never runs for it — see ResolveLeadCaptureTenant.
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
