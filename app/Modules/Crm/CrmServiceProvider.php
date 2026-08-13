<?php

namespace App\Modules\Crm;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Policies\LeadPolicy;
use App\Modules\Crm\Policies\LeadSourcePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the CRM module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model in a module directory, and
 * Filament treats a model with no policy as allowed — so without this map every
 * resource here would be open to any authenticated user.
 */
class CrmServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Lead::class => LeadPolicy::class,
        LeadSource::class => LeadSourcePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
