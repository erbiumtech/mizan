<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a workflow refuses — docs/project-management-plan.md §4.3.
 *
 * **Four kinds, and the ceiling is deliberate.** Jira expresses this as conditions, validators and
 * post functions, which together are a programming language stored in a database; the honest
 * description of where that goes is "unmaintainable". Four rows cover what teams here actually
 * configure, and the rule builder of docs/saas-launch-plan.md §8.9 is where a fifth would go — a
 * form over a registry, for the same reason.
 */
class WorkWorkflowRule extends Model
{
    use Auditable;

    /** From this status, only to these. */
    public const ONLY_TO = 'only_to';

    /** Entering this status requires a resolution to be chosen. */
    public const REQUIRES_RESOLUTION = 'requires_resolution';

    /** Entering this status requires somebody to be assigned. */
    public const REQUIRES_ASSIGNEE = 'requires_assignee';

    /** Only the assignee, or somebody who may manage the board, may leave this status. */
    public const ASSIGNEE_OR_MANAGER_ONLY = 'assignee_or_manager_only';

    public const KINDS = [
        self::ONLY_TO => 'May only move to certain statuses',
        self::REQUIRES_RESOLUTION => 'Requires a resolution',
        self::REQUIRES_ASSIGNEE => 'Requires an assignee',
        self::ASSIGNEE_OR_MANAGER_ONLY => 'Only the assignee or a manager may move it on',
    ];

    protected $fillable = ['work_workflow_id', 'kind', 'work_status_id', 'to_status_ids'];

    protected $casts = ['to_status_ids' => 'array'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkWorkflow::class, 'work_workflow_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(WorkStatus::class, 'work_status_id');
    }
}
