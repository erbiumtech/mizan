<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A version something ships in — docs/project-management-plan.md §6.4.
 *
 * An item's `release_id` is its *fix version*. The release itself is a name, a date it is meant for
 * and the date it actually went out; everything else about it — what is in it, what is still open,
 * the release notes — is read from the items, so the row can never disagree with them.
 */
class WorkRelease extends Model
{
    use Auditable;

    protected $fillable = [
        'project_id', 'name', 'description', 'planned_on', 'released_on',
    ];

    protected $casts = [
        'planned_on' => 'date',
        'released_on' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'release_id');
    }

    /** Not yet out: what the backlog may still be promised into. */
    public function scopeUnreleased(Builder $query): Builder
    {
        return $query->whereNull('released_on');
    }

    public function isReleased(): bool
    {
        return $this->released_on !== null;
    }
}
