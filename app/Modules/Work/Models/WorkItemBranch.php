<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A branch whose name carries an item's key — docs/project-management-plan.md §8.4. Marked deleted, never removed. */
class WorkItemBranch extends Model
{
    protected $fillable = ['work_item_id', 'work_repository_id', 'name', 'url', 'deleted_at'];

    protected $casts = ['deleted_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(WorkRepository::class, 'work_repository_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
