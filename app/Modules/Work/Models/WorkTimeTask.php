<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time tracker's task, which is one work item — docs/project-management-plan.md §8.8.
 *
 * Created lazily: the first time somebody starts a timer on the item, or when it enters an
 * in-progress status if the project says so. Named `WRK-12 · title` so it reads the same in
 * both places.
 */
class WorkTimeTask extends Model
{
    protected $fillable = ['work_item_id', 'work_time_project_id', 'external_task_id', 'name'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    public function timeProject(): BelongsTo
    {
        return $this->belongsTo(WorkTimeProject::class, 'work_time_project_id');
    }
}
