<?php

namespace App\Modules\Performance\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Performance\Services\TaskRoller;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Today's task occurrences and yesterday's misses — docs/saas-launch-plan.md §8.2.
 * Daily at 00:05; idempotent, so a re-run after a failed night changes nothing.
 */
class RollTasks extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'tasks:roll {--tenant=*} {--date= : Treat this date as today}';

    protected $description = "Create today's recurring-task occurrences and mark the ones past their grace as missed";

    public function handle(TaskRoller $roller): int
    {
        if ($this->skipsDisabledModule('performance')) {
            return self::SUCCESS;
        }

        $result = $roller->roll($this->option('date') ? Carbon::parse($this->option('date')) : null);

        $this->info("{$result['created']} occurrence(s) created, {$result['missed']} marked missed.");

        return self::SUCCESS;
    }
}
