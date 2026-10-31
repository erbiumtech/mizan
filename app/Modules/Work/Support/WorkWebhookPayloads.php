<?php

namespace App\Modules\Work\Support;

use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkSprint;

/**
 * What each of the eight outgoing webhooks carries — docs/project-management-plan.md §10.4.
 *
 * **Named fields, never `toArray()`.** A payload is a public contract with somebody else's system,
 * and the row would change it the day a column is added. It carries the key, the project key, the
 * status and its category, the assignee's email — and never the description, because the
 * description is the one field a subscriber should fetch rather than be sent: it is the one that
 * leaks.
 */
class WorkWebhookPayloads
{
    /** @return array<string, mixed> */
    public static function item(WorkItem $item): array
    {
        $item->loadMissing(['project', 'status', 'assignee.user']);

        return [
            'id' => (int) $item->getKey(),
            'key' => $item->key,
            'project' => $item->project?->key,
            'type' => $item->type,
            'title' => $item->title,
            'status' => $item->status?->name,
            'category' => $item->status?->category,
            'priority' => $item->priority,
            'assignee' => $item->assignee?->user?->email,
            'sprint_id' => $item->sprint_id !== null ? (int) $item->sprint_id : null,
            'release_id' => $item->release_id !== null ? (int) $item->release_id : null,
            'due_on' => $item->due_on?->toDateString(),
            'resolution' => $item->resolution,
            'resolved_at' => $item->resolved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function sprint(WorkSprint $sprint): array
    {
        $sprint->loadMissing('project');

        return [
            'id' => (int) $sprint->getKey(),
            'project' => $sprint->project?->key,
            'name' => $sprint->name,
            'goal' => $sprint->goal,
            'state' => $sprint->state,
            'starts_on' => $sprint->starts_on?->toDateString(),
            'ends_on' => $sprint->ends_on?->toDateString(),
            'started_at' => $sprint->started_at?->toIso8601String(),
            'closed_at' => $sprint->closed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function release(WorkRelease $release): array
    {
        $release->loadMissing('project');

        return [
            'id' => (int) $release->getKey(),
            'project' => $release->project?->key,
            'name' => $release->name,
            'planned_on' => $release->planned_on?->toDateString(),
            'released_on' => $release->released_on?->toDateString(),
        ];
    }
}
