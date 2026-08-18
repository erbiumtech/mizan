<?php

namespace App\Modules\Expenses;

use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Policies\ExpenseClaimPolicy;
use App\Modules\Expenses\Services\ExpenseClaimService;
use App\Support\Contracts\ReimbursableClaims;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies are registered explicitly: Laravel's App\Models\X -> App\Policies\XPolicy
 * guess cannot resolve a model in a module directory, and Filament treats a model
 * with no policy as allowed.
 */
class ExpensesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ExpenseClaim::class => ExpenseClaimPolicy::class,
    ];

    public function register(): void
    {
        // What an employee is owed back, for the payslip that reimburses it. Same inversion as Advances and
        // for the same reason: `expenses` requires `payroll`, so Payroll asking a contract is the only
        // direction that is not a cycle. See docs/module-packaging-plan.md §11.
        $this->app->bind(ReimbursableClaims::class, ExpenseClaimService::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
