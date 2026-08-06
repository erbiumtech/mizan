<?php

namespace App\Modules\PersonalFinance;

use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\PersonalEntry;
use App\Modules\PersonalFinance\Models\PersonalTaxProfile;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Policies\PersonalAccountPolicy;
use App\Modules\PersonalFinance\Policies\PersonalEntryPolicy;
use App\Modules\PersonalFinance\Policies\PersonalTaxProfilePolicy;
use App\Modules\PersonalFinance\Policies\TaxSchedulePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Policies are registered explicitly: Laravel's App\Models\X -> App\Policies\XPolicy
 * guess cannot resolve a model in a module directory, and Filament treats a model
 * with no policy as allowed.
 *
 * Note that in this module the policies are not what keeps one person's records
 * away from another's — Gate::before in AppServiceProvider hands Administrators
 * and super admins every ability except `create`, so a policy checking
 * `$record->user_id === $user->id` would pass for them. Ownership is enforced by
 * the BelongsToOwner global scope and by model-level guards; the policies carry
 * the permission checks only.
 */
class PersonalFinanceServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        PersonalAccount::class => PersonalAccountPolicy::class,
        PersonalEntry::class => PersonalEntryPolicy::class,
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
