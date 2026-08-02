<?php

namespace App\Modules\Payroll;

use App\Modules\Payroll\Console\Commands\CheckPayrollAccounts;
use App\Modules\Payroll\Console\Commands\OpenPayrollMonth;
use App\Modules\Payroll\Models\AnnualTax;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Payroll\Policies\AnnualTaxPolicy;
use App\Modules\Payroll\Policies\PayslipPolicy;
use App\Modules\Payroll\Policies\SalarySlabPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Payroll module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class PayrollServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        AnnualTax::class => AnnualTaxPolicy::class,
        Payslip::class => PayslipPolicy::class,
        SalarySlab::class => SalarySlabPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/routes/console.php');

        // Laravel only auto-discovers commands in app/Console/Commands,
        // so a moved command has to be registered here or it disappears
        // from artisan — and from the scheduler, silently.
        $this->commands([CheckPayrollAccounts::class, OpenPayrollMonth::class]);
    }
}
