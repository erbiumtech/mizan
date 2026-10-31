<?php

namespace App\Modules\Work\Support;

use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\User;
use App\Modules\Work\Events\WorkItemAssigned;
use App\Modules\Work\Events\WorkItemCommented;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Notifications\WorkItemAssigned as WorkItemAssignedNotification;
use App\Modules\Work\Notifications\WorkItemMentioned;
use App\Support\ModuleMap;

/**
 * Who hears what, wired onto the models — docs/project-management-plan.md §10.1 and §10.3.
 *
 * Three rules, each a model event:
 *
 *  - **assigning adds the assignee to the watchers**, and tells them — unless they assigned it to
 *    themselves, because nobody is told about their own edit;
 *  - **commenting adds the commenter**, and tells anybody the comment mentions;
 *  - **a mention in the description** tells the person, once, when the description changes.
 *
 * Everything else — "a record of yours changed" on the bell — is `RecordAudience` reading
 * `WorkItem::changeAudience()`, which needs no wiring here.
 *
 * `created` and `updated` are listened to separately rather than `saved` with `wasRecentlyCreated`:
 * that flag stays true for the life of the instance, so a title change on an item just created
 * would have read as a second assignment.
 */
class WorkWatching
{
    public static function register(): void
    {
        WorkItem::created(function (WorkItem $item): void {
            if ($item->assignee_employee_id !== null) {
                self::assigned($item);
            }

            self::notifyMentioned($item, $item->description, 'the description');
        });

        WorkItem::updated(function (WorkItem $item): void {
            if ($item->wasChanged('assignee_employee_id') && $item->assignee_employee_id !== null) {
                self::assigned($item);
            }

            if ($item->wasChanged('description')) {
                self::notifyMentioned($item, $item->description, 'the description');
            }
        });

        Comment::created(function (Comment $comment): void {
            self::commented($comment);
        });
    }

    private static function assigned(WorkItem $item): void
    {
        // The moment the assignee last changed — what the digest's "since yesterday" reads, because
        // `updated_at` moves on every edit. Quietly, so this stamp is not itself an update.
        $item->forceFill(['assigned_at' => now()])->saveQuietly();

        $assignee = $item->assignee()->with('user')->first()?->user;

        if (! $assignee instanceof User) {
            return;
        }

        $item->watch($assignee);

        $previous = $item->getOriginal('assignee_employee_id');
        WorkItemAssigned::dispatch($item, $previous !== null && (int) $previous !== (int) $item->assignee_employee_id ? (int) $previous : null);

        $by = auth()->user();

        if ($by instanceof User && (int) $by->getKey() === (int) $assignee->getKey()) {
            return;
        }

        $assignee->notify(new WorkItemAssignedNotification($item, $by?->name));
    }

    private static function commented(Comment $comment): void
    {
        if ($comment->commentable_type !== ModuleMap::alias(WorkItem::class)) {
            return;
        }

        $item = WorkItem::query()->find($comment->commentable_id);

        if ($item === null) {
            return;
        }

        if ($comment->user_id !== null) {
            $item->watch((int) $comment->user_id);
        }

        WorkItemCommented::dispatch($item, $comment);

        self::notifyMentioned($item, $comment->body, 'a comment', $comment->user_id !== null ? (int) $comment->user_id : null);
    }

    private static function notifyMentioned(WorkItem $item, ?string $text, string $where, ?int $byId = null): void
    {
        if (blank($text)) {
            return;
        }

        $byId ??= auth()->id() !== null ? (int) auth()->id() : null;
        $by = $byId !== null ? User::query()->find($byId) : null;

        foreach (Mentions::usersIn($text) as $user) {
            // Nobody is told about their own edit, and a mention is still an edit.
            if ($byId !== null && (int) $user->getKey() === $byId) {
                continue;
            }

            $item->watch($user);
            $user->notify(new WorkItemMentioned($item, $by?->name, $where));
        }
    }
}
