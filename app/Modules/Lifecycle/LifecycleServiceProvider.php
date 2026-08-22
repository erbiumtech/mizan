<?php

namespace App\Modules\Lifecycle;

use App\Modules\Lifecycle\Console\Commands\CheckDocumentExpiry;
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
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/console.php');

        $this->commands([CheckDocumentExpiry::class]);
    }
}
