<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A column heading, and the category that makes it mean something.
 *
 * **The category is the engine and the name is the label** — docs/project-management-plan.md §4.2.
 * A company may call a status anything; what the application reasons about is whether it is
 * `todo`, `in_progress` or `done`. That is why *In review* and *In QA* both behave as in progress
 * without being configured to, and why a burndown, a cycle time and a "what is still open" filter
 * work for a workflow nobody has seen yet.
 */
class WorkStatus extends Model
{
    use Auditable;

    public const TODO = 'todo';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    /** The three, in the order work passes through them. */
    public const CATEGORIES = [
        self::TODO => 'To do',
        self::IN_PROGRESS => 'In progress',
        self::DONE => 'Done',
    ];

    protected $fillable = ['work_workflow_id', 'name', 'category', 'sort', 'colour'];

    protected $casts = ['sort' => 'integer'];

    protected $attributes = ['category' => self::TODO, 'colour' => 'gray'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkWorkflow::class, 'work_workflow_id');
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function isDone(): bool
    {
        return $this->category === self::DONE;
    }

    public function isInProgress(): bool
    {
        return $this->category === self::IN_PROGRESS;
    }
}
