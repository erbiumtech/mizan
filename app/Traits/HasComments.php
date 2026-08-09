<?php

namespace App\Traits;

use App\Modules\Core\Models\Comment;

trait HasComments
{
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function unresolvedComments()
    {
        return $this->comments()->whereNull('resolved_at')->whereNull('parent_id');
    }
}
