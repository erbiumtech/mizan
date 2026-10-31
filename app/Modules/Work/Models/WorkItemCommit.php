<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A commit that named an item — docs/project-management-plan.md §8.4. */
class WorkItemCommit extends Model
{
    protected $fillable = [
        'work_item_id', 'work_repository_id', 'sha', 'message', 'integration_identity_id', 'committed_at', 'url',
    ];

    protected $casts = ['committed_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(WorkRepository::class, 'work_repository_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(IntegrationIdentity::class, 'integration_identity_id');
    }

    public function shortSha(): string
    {
        return substr($this->sha, 0, 7);
    }
}
