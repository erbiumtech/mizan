<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\ModuleMap;
use App\Traits\Auditable;

class Comment extends Model
{
    use Auditable;

    protected $fillable = [
        'commentable_type', 'commentable_id', 'user_id', 'body',
        'parent_id', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /**
     * Normalise on write: `commentable_type` holds the model's stable alias (ModuleMap), never its live class
     * name, as JournalEntry::source_type and CustomField::model_type already do. Laravel 13.38 resolves a
     * morph type only through the enforced map and throws on a bare class name, so a row written with
     * `Model::class` by a seeder, an import or a test could no longer be read back.
     */
    public function setCommentableTypeAttribute(?string $value): void
    {
        $this->attributes['commentable_type'] = $value === null ? null : ModuleMap::alias($value);
    }

    public function commentable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
