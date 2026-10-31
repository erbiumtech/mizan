<?php

namespace App\Modules\Work\Events;

use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A work item changed status — the one transition every board, form and merged pull request goes through.
 *
 * One of the eight domain events docs/project-management-plan.md §10.4 names; each feeds an outgoing
 * webhook type registered in `WorkServiceProvider`, and anything else that wants to know may listen.
 */
class WorkItemMoved
{
    use Dispatchable;

    public function __construct(public readonly WorkItem $item, public readonly ?WorkStatus $from, public readonly WorkStatus $to) {}
}
