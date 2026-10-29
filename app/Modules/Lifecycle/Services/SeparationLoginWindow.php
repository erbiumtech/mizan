<?php

namespace App\Modules\Lifecycle\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Support\Contracts\LoginWindow;
use Throwable;

/**
 * A leaver's login closes a configurable number of days after their last working
 * day — docs/saas-launch-plan.md §14.5.
 *
 * **It fails open.** This runs on every panel request, before the company is
 * current, so it has to reach into the tenant's database to read the employee.
 * Anything that goes wrong there — an unmigrated tenant, a connection the test
 * suite does not configure — must leave the person signed in rather than lock a
 * whole company out of its own panel over a grace period.
 */
class SeparationLoginWindow implements LoginWindow
{
    public function isOpen(User $user, Company $company): bool
    {
        if (! modules()->enabled('lifecycle')) {
            return true;
        }

        try {
            $employee = Company::current()?->is($company)
                ? Employee::query()->where('user_id', $user->getKey())->first()
                : $company->execute(fn () => Employee::query()->where('user_id', $user->getKey())->first());
        } catch (Throwable) {
            return true;
        }

        return $employee === null || Separations::loginStillOpen($employee);
    }
}
