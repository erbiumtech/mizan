<?php

namespace App\Policies;

use App\Models\Comment;
use App\Modules\Payroll\Models\Payslip;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('CommentView');
    }

    public function view(User $user, Comment $comment): bool
    {
        if (! $user->hasPermissionTo('CommentView')) {
            return false;
        }

        // Staff (resolvers) see all; employees only comments on their own records.
        if ($user->hasPermissionTo('CommentResolve')) {
            return true;
        }

        return $this->ownsCommentable($user, $comment);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('CommentCreate');
    }

    public function update(User $user, Comment $comment): bool
    {
        // Author may edit until someone replies or it is resolved.
        return $comment->user_id === $user->id
            && ! $comment->isResolved()
            && ! $comment->replies()->exists();
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $this->update($user, $comment);
    }

    public function resolve(User $user, Comment $comment): bool
    {
        return $user->hasPermissionTo('CommentResolve');
    }

    protected function ownsCommentable(User $user, Comment $comment): bool
    {
        $commentable = $comment->commentable;

        if ($commentable instanceof Payslip) {
            return $commentable->employee && $commentable->employee->user_id === $user->id;
        }

        return false;
    }
}
