<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fixed stretch of work with a goal — docs/project-management-plan.md §6.2. */
class WorkSprint extends Model
{
    use Auditable;

    public const FUTURE = 'future';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    public const STATES = [
        self::FUTURE => 'Planned',
        self::ACTIVE => 'Active',
        self::CLOSED => 'Closed',
    ];

    protected $fillable = [
        'project_id', 'name', 'goal', 'starts_on', 'ends_on', 'state', 'started_at', 'closed_at',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $attributes = ['state' => self::FUTURE];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'sprint_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE;
    }

    public function isClosed(): bool
    {
        return $this->state === self::CLOSED;
    }
}
