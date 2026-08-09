<?php

namespace App\Modules\Employees;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Employees\Policies\EmployeeChangeRequestPolicy;
use App\Modules\Employees\Policies\EmployeePolicy;
use App\Modules\Employees\Policies\EmployeeSettingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Employees module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class EmployeesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        EmployeeChangeRequest::class => EmployeeChangeRequestPolicy::class,
        Employee::class => EmployeePolicy::class,
        EmployeeSetting::class => EmployeeSettingPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
    }
}
