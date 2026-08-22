<?php

namespace App\Modules\Employees\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Employees\Services\JobHistory;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Apply job changes on the day they take effect.
 *
 * A transfer agreed in August and effective in September is recorded in
 * `employee_job_history` when it is agreed, and `JobHistory::record()` refuses to
 * project it onto `employees` until it starts — otherwise the employee would read
 * as already transferred, which is exactly the confusion between "what is true"
 * and "what was decided" that the history table exists to end.
 *
 * This is what closes that loop. Without it, future-dating records a row that
 * never takes effect and the columns stay behind for ever.
 *
 * Idempotent: an employee already matching their in-force row is not written, so
 * a daily sweep over a settled company does nothing. That matters because this
 * runs every day over data that mostly changed months ago.
 */
class ApplyDueJobChanges extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'employees:apply-job-changes {--tenant=*}';

    protected $description = 'Move employee designation, department, manager and employment type onto any job history row that takes effect today';

    public function handle(JobHistory $history): int
    {
        if ($this->skipsDisabledModule('employees')) {
            return self::SUCCESS;
        }

        $applied = $history->applyDueChanges();

        // Named rather than counted. "3 employees updated" is the report that
        // sends somebody looking through the table to find out who; the whole
        // point of a scheduled write is that nobody watched it happen.
        $this->info($applied === []
            ? 'No job changes were due.'
            : 'Applied job changes for: '.implode(', ', $applied).'.');

        return self::SUCCESS;
    }
}
