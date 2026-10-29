<?php

namespace App\Modules\Lifecycle\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Lifecycle\Models\EmployeeChecklist;
use App\Modules\Lifecycle\Services\ChecklistService;
use App\Modules\Lifecycle\Services\Separations;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/** Auto-check items closed from the records, and separations closed after their last day — docs/saas-launch-plan.md §14.5. */
class RunLifecycleChecks extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'lifecycle:run-checks {--tenant=*}';

    protected $description = 'Close checklist items whose facts are now true, and separations past their last working day';

    public function handle(ChecklistService $checklists, Separations $separations): int
    {
        if ($this->skipsDisabledModule('lifecycle')) {
            return self::SUCCESS;
        }

        $closedItems = 0;

        foreach (EmployeeChecklist::query()->whereNull('completed_on')->with('employee')->get() as $checklist) {
            $closedItems += $checklists->autoCheck($checklist);
        }

        $closedSeparations = $separations->closeDue();

        $this->info("{$closedItems} checklist item(s) closed from the records; {$closedSeparations} separation(s) closed.");

        return self::SUCCESS;
    }
}
