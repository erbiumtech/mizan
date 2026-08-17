<?php

namespace App\Modules\Advances;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Policies\AdvancePolicy;
use App\Modules\Advances\Services\AdvanceService;
use App\Support\Contracts\AdvanceLedger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies are registered explicitly: Laravel's App\Models\X -> App\Policies\XPolicy
 * guess cannot resolve a model in a module directory, and Filament treats a model
 * with no policy as allowed.
 */
class AdvancesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Advance::class => AdvancePolicy::class,
    ];

    public function register(): void
    {
        // What an employee owes on an advance, for the payslip that deducts it. Payroll asks the contract
        // rather than naming this module — `advances` requires `payroll`, so that was a cycle. Replaces the
        // null default in ContractDefaultsServiceProvider; the licence guard is inside AdvanceService, so
        // binding unconditionally is correct. See docs/module-packaging-plan.md §11.
        $this->app->bind(AdvanceLedger::class, AdvanceService::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
