<?php

namespace App\Modules\ConstructionCosting;

use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\ConstructionCosting\Policies\CommitmentPolicy;
use App\Modules\ConstructionCosting\Policies\CostEntryPolicy;
use App\Modules\ConstructionCosting\Policies\CostPeriodPolicy;
use App\Modules\ConstructionCosting\Policies\JobBudgetPolicy;
use App\Modules\ConstructionCosting\Policies\GoodsReceiptPolicy;
use App\Modules\ConstructionCosting\Policies\RequisitionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the cost ledger owns that Filament does not discover.
 *
 * Policies explicitly: Laravel's App\Models\X -> App\Policies\XPolicy guess cannot resolve a model in a module
 * directory, and Filament treats a model with no policy as allowed — so a missing registration is an open
 * resource. `ModuleCoverageTest` fails the build for one.
 */
class ConstructionCostingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        JobBudget::class => JobBudgetPolicy::class,
        Commitment::class => CommitmentPolicy::class,
        CostEntry::class => CostEntryPolicy::class,
        CostPeriod::class => CostPeriodPolicy::class,
        GoodsReceipt::class => GoodsReceiptPolicy::class,
        Requisition::class => RequisitionPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
