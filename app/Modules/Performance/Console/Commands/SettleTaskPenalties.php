<?php

namespace App\Modules\Performance\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Performance\Services\TaskRoller;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * The month's task penalties, per employee — docs/saas-launch-plan.md §8.2.
 *
 * Reports, and writes no payslip. The plan had this write one component input
 * per employee; the input store turned out to be rebuilt from the typed
 * overrides on every payslip save and read only for the legacy keys, so a row
 * for a penalty component would be wiped and never paid. Until the payroll
 * engine grows a key for it, the figure is here for the clerk to enter as a
 * deduction — which keeps the HRMS plan's rule that no pay-affecting switch
 * acts without a person.
 */
class SettleTaskPenalties extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'tasks:settle {--tenant=*} {--month= : Any date in the month; defaults to this month}';

    protected $description = "List each employee's unexcused task penalties for the month, for payroll to enter";

    public function handle(TaskRoller $roller): int
    {
        if ($this->skipsDisabledModule('performance')) {
            return self::SUCCESS;
        }

        $month = Carbon::parse($this->option('month') ?: now())->startOfMonth();
        $rows = $roller->monthlyPenalties($month);

        if ($rows->isEmpty()) {
            $this->line('No unexcused task penalties with an amount in '.$month->format('F Y').'.');

            return self::SUCCESS;
        }

        $this->table(
            ['Employee', 'Misses', 'Amount'],
            $rows->map(fn (array $row): array => [
                $row['employee']->display_label,
                $row['misses'],
                number_format($row['amount'], 2),
            ])->all(),
        );

        $this->comment('Nothing was written to any payslip. Enter these as deductions on the month\'s payslips.');

        return self::SUCCESS;
    }
}
