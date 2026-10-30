<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A tag on an item. A null project means every project may use it. */
class WorkLabel extends Model
{
    use Auditable;

    protected $fillable = ['project_id', 'name', 'colour'];

    protected $attributes = ['colour' => 'gray'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\Project::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(WorkItem::class, 'work_item_labels');
    }
}
