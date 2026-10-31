<?php

namespace App\Modules\Work\Events;

use App\Modules\Work\Models\WorkSprint;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sprint was closed, with its unfinished work sent somewhere.
 *
 * One of the eight domain events docs/project-management-plan.md §10.4 names; each feeds an outgoing
 * webhook type registered in `WorkServiceProvider`, and anything else that wants to know may listen.
 */
class SprintCompleted
{
    use Dispatchable;

    public function __construct(public readonly WorkSprint $sprint, public readonly ?WorkSprint $movedTo) {}
}
