<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item's relationship to another — docs/project-management-plan.md §3.5.
 *
 * **Stored once, in the forward direction.** "A blocks B" and "B is blocked by A" are the same
 * fact; writing both would be two rows that can disagree, and the day they do is the day somebody
 * is told an item is blocked by something that was never blocking it. The reverse is read.
 */
class WorkItemLink extends Model
{
    use Auditable;

    public const BLOCKS = 'blocks';

    public const RELATES_TO = 'relates_to';

    public const DUPLICATES = 'duplicates';

    /** What the row says, read from the item the link starts at. */
    public const FORWARD = [
        self::BLOCKS => 'blocks',
        self::RELATES_TO => 'relates to',
        self::DUPLICATES => 'duplicates',
    ];

    /** The same row, read from the other end. */
    public const REVERSE = [
        self::BLOCKS => 'is blocked by',
        self::RELATES_TO => 'relates to',
        self::DUPLICATES => 'is duplicated by',
    ];

    protected $fillable = ['from_item_id', 'to_item_id', 'kind'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'from_item_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'to_item_id');
    }
}
