<?php

namespace App\Modules\Work\Events;

use App\Modules\Work\Models\WorkRelease;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A release went out.
 *
 * One of the eight domain events docs/project-management-plan.md §10.4 names; each feeds an outgoing
 * webhook type registered in `WorkServiceProvider`, and anything else that wants to know may listen.
 */
class ReleaseReleased
{
    use Dispatchable;

    public function __construct(public readonly WorkRelease $release) {}
}
