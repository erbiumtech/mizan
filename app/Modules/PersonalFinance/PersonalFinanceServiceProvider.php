<?php

namespace App\Modules\PersonalFinance;

use App\Modules\PersonalFinance\Models\PersonalTaxProfile;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Policies\PersonalTaxProfilePolicy;
use App\Modules\PersonalFinance\Policies\TaxSchedulePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies are registered explicitly: Laravel's App\Models\X -> App\Policies\XPolicy
 * guess cannot resolve a model in a module directory, and Filament treats a model
 * with no policy as allowed.
 *
 * Both models here are per-tenant reference data rather than per-user records: a
 * personal account IS a tenant, so its tax brackets and its tax profile belong to
 * the account, and everybody who can reach that account can see them. There is no
 * per-user scoping, because an accountant doing somebody's books has to be able
 * to read them.
 */
class PersonalFinanceServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        PersonalTaxProfile::class => PersonalTaxProfilePolicy::class,
        TaxSchedule::class => TaxSchedulePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
