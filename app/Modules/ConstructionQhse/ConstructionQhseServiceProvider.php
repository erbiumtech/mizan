<?php

namespace App\Modules\ConstructionQhse;

use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Modules\ConstructionQhse\Policies\InspectionPolicy;
use App\Modules\ConstructionQhse\Policies\ItpPolicy;
use App\Modules\ConstructionQhse\Policies\NcrPolicy;
use App\Modules\ConstructionQhse\Policies\QhseActionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything this module owns that Filament does not discover.
 *
 * Policies explicitly: Laravel's App\Models\X -> App\Policies\XPolicy guess cannot resolve a model in a module
 * directory, and Filament treats a model with no policy as allowed — so a missing registration is an open resource.
 * `ModuleCoverageTest` fails the build for one.
 */
class ConstructionQhseServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Itp::class => ItpPolicy::class,
        Inspection::class => InspectionPolicy::class,
        Ncr::class => NcrPolicy::class,
        QhseAction::class => QhseActionPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
