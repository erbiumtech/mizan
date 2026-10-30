<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file on an item. Served through the tenant-file route, never by a public URL. */
class WorkAttachment extends Model
{
    use Auditable;

    protected $fillable = ['work_item_id', 'user_id', 'name', 'path', 'size', 'mime'];

    protected $casts = ['size' => 'integer'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    /** Whoever uploaded it owns it, which is what CommentPolicy-style ownership asks. */
    public function isOwnedBy(\App\Modules\Core\Models\User $user): bool
    {
        return $this->user_id !== null && (int) $this->user_id === (int) $user->getKey();
    }
}
