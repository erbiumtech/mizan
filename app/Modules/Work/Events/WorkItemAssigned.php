<?php

namespace App\Modules\Work\Events;

use App\Modules\Work\Models\WorkItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A work item got, or changed, its assignee.
 *
 * One of the eight domain events docs/project-management-plan.md §10.4 names; each feeds an outgoing
 * webhook type registered in `WorkServiceProvider`, and anything else that wants to know may listen.
 */
class WorkItemAssigned
{
    use Dispatchable;

    public function __construct(public readonly WorkItem $item, public readonly ?int $previousEmployeeId) {}
}
