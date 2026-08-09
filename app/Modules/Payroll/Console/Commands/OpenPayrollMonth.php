<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Raise the month's payslips, on the 26th (see the module's routes/console.php).
 *
 * Payslips only. Salary payments are still generated when the Bank Payment File
 * is opened, which keeps one place responsible for payments and avoids drafts
 * standing against payslips nobody has looked at — a salary cannot be released
 * until the employee accepts it either way.
 */
class OpenPayrollMonth extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'payroll:open-month
                            {--month= : Month name, e.g. August. Defaults to the month being run}
                            {--fiscal-year= : Fiscal year id. Defaults to the active one}
                            {--dry-run : List what would be raised without writing anything}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = "Raise payslips for the month's payroll";

    public function handle(MonthlyPayrollService $payroll): int
    {
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        $month = $this->option('month') ?: now()->format('F');

        $fiscalYear = $this->option('fiscal-year')
            ? FiscalYear::find($this->option('fiscal-year'))
            : FiscalYear::current();

        if (! $fiscalYear) {
            $this->error('No active fiscal year, and none given. Nothing was raised.');

            return self::FAILURE;
        }

        $due = $payroll->employeesDueAPayslip($month, $fiscalYear);
        $unpaid = $payroll->employeesWithoutASetting($month, $fiscalYear);

        $this->line("{$month} {$fiscalYear->name}: ".$due->count().' payslip(s) to raise.');

        if ($unpaid->isNotEmpty()) {
            // Usually an oversight rather than a decision, and invisible otherwise:
            // the month simply comes out short a person.
            $this->warn($unpaid->count().' active employee(s) have no salary settings covering this month and were skipped:');

            foreach ($unpaid as $employee) {
                $this->line('  - '.($employee->user?->name ?? "employee #{$employee->id}"));
            }
        }

        if ($this->option('dry-run')) {
            foreach ($due as $employee) {
                $this->line('  + '.($employee->user?->name ?? "employee #{$employee->id}"));
            }

            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $created = $payroll->openMonth($month, $fiscalYear);

        $this->info("Raised {$created->count()} payslip(s) for {$month} {$fiscalYear->name}.");

        return self::SUCCESS;
    }
}
