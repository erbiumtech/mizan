<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/** Take an imported batch back whole — docs/saas-launch-plan.md §14.1. */
class RevertSalaryImport extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'payroll:revert-import {batch : The batch id the import wrote} {--tenant=*}';

    protected $description = 'Delete every payslip a salary-history import wrote, and reopen the runs it signed off';

    public function handle(): int
    {
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        $batch = (string) $this->argument('batch');
        $payslips = Payslip::query()->where('import_batch', $batch)->get();

        if ($payslips->isEmpty()) {
            $this->warn("No payslips carry batch {$batch}.");

            return self::SUCCESS;
        }

        foreach (PayrollRun::query()->whereKey($payslips->pluck('payroll_run_id')->unique())->get() as $run) {
            if ($run->isLocked()) {
                $run->update(['status' => PayrollRun::STATUS_OPEN, 'reopen_reason' => "Imported batch {$batch} reverted"]);
            }
        }

        $deleted = 0;

        foreach ($payslips as $payslip) {
            $payslip->imported = true;
            $payslip->delete();
            $deleted++;
        }

        // A run left with nothing in it was made by the import; it goes too.
        PayrollRun::query()->whereKey($payslips->pluck('payroll_run_id')->unique())->doesntHave('payslips')->delete();

        $this->info("{$deleted} payslip(s) from batch {$batch} deleted.");

        return self::SUCCESS;
    }
}
