<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\User;

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

        // Asked of the model, not decided here.
        //
        // This was `$commentable instanceof Payslip`, which made Core's comment policy depend on Payroll
        // for one question (docs/module-packaging-plan.md §9). Any commentable model may answer it by
        // declaring `isOwnedBy(User): bool` — duck-typed so Core owns no contract for it — and the
        // self-service visibility a payslip has is available to an expense claim or a leave request by
        // implementing that one method. Saying "this is yours" grants nothing by itself: the policy
        // still requires the permission; ownership only widens what "own rows" means.
        return $commentable !== null
            && method_exists($commentable, 'isOwnedBy')
            && $commentable->isOwnedBy($user);
    }
}
