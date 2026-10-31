<?php

namespace App\Modules\Work\Imports;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkLabel;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\WorkItems;
use RuntimeException;

/**
 * A Trello board's JSON, into a project — docs/project-management-plan.md §8.9.
 *
 * Trello has lists where this has statuses, so each list maps onto the project's workflow by
 * name, and a list the workflow does not know falls to the category its name suggests — a list
 * called "Done" is done, "Doing" is in progress, anything else is to do. Cards in a list are
 * ranked in Trello's own order. Members carry no email in the export, so nothing is assigned;
 * the member names are kept in the description's footer rather than guessed at.
 */
class TrelloImport
{
    public function __construct(private readonly WorkItems $items) {}

    /**
     * @param  array<string, mixed>  $board  the decoded export
     * @return array{imported: int, skipped: int, notes: array<int, string>}
     *
     * @throws RuntimeException
     */
    public function run(Project $project, array $board): array
    {
        if (! isset($board['cards']) || ! is_array($board['cards'])) {
            throw new RuntimeException('The file has no "cards": it does not look like a Trello board export.');
        }

        $workflow = $project->work_workflow_id !== null
            ? WorkWorkflow::query()->with('statuses')->whereKey($project->work_workflow_id)->first()
            : WorkWorkflow::default()?->load('statuses');

        if ($workflow === null) {
            throw new RuntimeException('This company has no workflow to put the cards in.');
        }

        $lists = collect($board['lists'] ?? [])->keyBy('id');
        $members = collect($board['members'] ?? [])->keyBy('id');
        $labels = collect($board['labels'] ?? [])->keyBy('id');
        $notes = [];
        $imported = 0;
        $skipped = 0;

        // Trello's own order within each list.
        $cards = collect($board['cards'])->sortBy([['idList', 'asc'], ['pos', 'asc']]);

        foreach ($cards as $card) {
            if (blank($card['id'] ?? null) || blank($card['name'] ?? null)) {
                $skipped++;

                continue;
            }

            $list = $lists->get($card['idList'] ?? null);
            $status = $this->statusFor($workflow, (string) ($list['name'] ?? ''));

            $memberNames = collect($card['idMembers'] ?? [])->map(fn ($id) => $members->get($id)['fullName'] ?? null)->filter()->values();
            $attachments = collect($card['attachments'] ?? [])->pluck('url')->filter()->values();

            $description = trim((string) ($card['desc'] ?? ''));
            $footer = array_filter([
                'Imported from Trello list "'.($list['name'] ?? '?').'"'.(! empty($card['shortUrl']) ? ' — '.$card['shortUrl'] : '').'.',
                $memberNames->isNotEmpty() ? 'Trello members: '.$memberNames->implode(', ') : null,
                $attachments->isNotEmpty() ? "Attachments:\n".$attachments->map(fn (string $u): string => '- '.$u)->implode("\n") : null,
            ]);

            $attributes = [
                'title' => mb_substr((string) $card['name'], 0, 255),
                'description' => ($description !== '' ? $description."\n\n" : '').'---'."\n".implode("\n", $footer),
                'due_on' => ! empty($card['due']) ? substr((string) $card['due'], 0, 10) : null,
                'imported_from' => 'trello:'.$card['id'],
            ];

            $existing = WorkItem::query()->where('project_id', $project->getKey())->where('imported_from', 'trello:'.$card['id'])->first();

            try {
                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $item = $existing;
                } else {
                    $item = $this->items->create($project, $attributes + ['work_status_id' => $status->getKey()]);
                }

                if ((int) $item->work_status_id !== (int) $status->getKey()) {
                    $item->moveTo($status, null, $status->isDone() ? 'Done' : null);
                }

                if (! empty($card['closed'])) {
                    $item->forceFill(['archived_at' => now()])->saveQuietly();
                }

                $this->labels($project, $item, collect($card['idLabels'] ?? [])->map(fn ($id) => $labels->get($id))->filter());
            } catch (RuntimeException $e) {
                $notes[] = $card['name'].': '.$e->getMessage();
            }

            $imported++;
        }

        $unknownLists = $lists->map(fn (array $l): string => (string) $l['name'])
            ->reject(fn (string $name): bool => $workflow->statuses->contains(fn (WorkStatus $s): bool => strcasecmp($s->name, $name) === 0))
            ->values();

        if ($unknownLists->isNotEmpty()) {
            $notes[] = 'Lists mapped by what their name suggests rather than by a status of the same name: '.$unknownLists->implode(', ').'.';
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'notes' => $notes];
    }

    private function statusFor(WorkWorkflow $workflow, string $listName): WorkStatus
    {
        $named = $workflow->statuses->first(fn (WorkStatus $s): bool => strcasecmp($s->name, trim($listName)) === 0);

        if ($named !== null) {
            return $named;
        }

        $lower = strtolower($listName);

        $category = match (true) {
            str_contains($lower, 'done'), str_contains($lower, 'complete'), str_contains($lower, 'shipped'), str_contains($lower, 'closed') => WorkStatus::DONE,
            str_contains($lower, 'doing'), str_contains($lower, 'progress'), str_contains($lower, 'review'), str_contains($lower, 'testing') => WorkStatus::IN_PROGRESS,
            default => WorkStatus::TODO,
        };

        return $workflow->firstOf($category) ?? $workflow->statuses->first();
    }

    /** @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $trelloLabels */
    private function labels(Project $project, WorkItem $item, $trelloLabels): void
    {
        if ($trelloLabels->isEmpty()) {
            return;
        }

        $ids = $trelloLabels->map(function (array $label) use ($project): ?int {
            $name = mb_substr(trim((string) ($label['name'] ?: $label['color'] ?: '')), 0, 40);

            if ($name === '') {
                return null;
            }

            return (int) WorkLabel::query()->firstOrCreate(
                ['project_id' => $project->getKey(), 'name' => $name],
                ['colour' => $this->colour((string) ($label['color'] ?? ''))],
            )->getKey();
        })->filter()->values()->all();

        $item->labels()->syncWithoutDetaching($ids);
    }

    private function colour(string $trello): string
    {
        return match (true) {
            str_contains($trello, 'red') => 'danger',
            str_contains($trello, 'orange'), str_contains($trello, 'yellow') => 'warning',
            str_contains($trello, 'green') => 'success',
            str_contains($trello, 'blue'), str_contains($trello, 'sky'), str_contains($trello, 'purple') => 'info',
            default => 'gray',
        };
    }
}
