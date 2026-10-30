<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of a connection's repositories, linked to one project — docs/project-management-plan.md §8.1. */
class WorkRepository extends Model
{
    use Auditable;

    protected $fillable = [
        'integration_connection_id', 'project_id', 'external_id', 'full_name', 'default_branch',
        'url', 'webhook_id', 'linked_at', 'unlinked_at',
    ];

    protected $casts = ['linked_at' => 'datetime', 'unlinked_at' => 'datetime'];

    /** Still delivering and still offered — not unlinked. */
    public function scopeLinked(Builder $query): Builder
    {
        return $query->whereNull('unlinked_at');
    }

    public function isLinked(): bool
    {
        return $this->unlinked_at === null;
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function commits(): HasMany
    {
        return $this->hasMany(WorkItemCommit::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(WorkItemBranch::class);
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(WorkItemPullRequest::class);
    }
}
