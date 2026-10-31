<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pull request whose title or branch named an item — docs/project-management-plan.md §8.4. */
class WorkItemPullRequest extends Model
{
    public const OPEN = 'open';

    public const MERGED = 'merged';

    public const CLOSED = 'closed';

    protected $fillable = [
        'work_item_id', 'work_repository_id', 'number', 'title', 'state', 'integration_identity_id',
        'url', 'head_branch', 'merged_at', 'checks_state',
    ];

    protected $casts = ['number' => 'integer', 'merged_at' => 'datetime'];

    protected $attributes = ['state' => self::OPEN];

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

    public function isOpen(): bool
    {
        return $this->state === self::OPEN;
    }
}
