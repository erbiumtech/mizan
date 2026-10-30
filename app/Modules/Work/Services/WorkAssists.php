<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\Comment;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Support\Ai\ModelUnavailable;
use App\Support\Ai\StructuredModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The three AI assists — docs/project-management-plan.md §12.
 *
 * All through `StructuredModel`, all absent when `ai.php` has no key, and **none of them on the
 * write path without a person clicking**: a draft is a prefilled form that is saved only when
 * somebody saves it, a summary is regenerated on request and marked as generated, an estimate is
 * a number beside the field that is never written. That is the ceiling; auto-assignment and
 * sprint planning by model are §17.7.
 */
class WorkAssists
{
    /** A thread is worth summarising past this many comments — §12, assist 2. */
    public const SUMMARY_FROM = 10;

    public function __construct(private readonly StructuredModel $model) {}

    public function isAvailable(): bool
    {
        return $this->model->isConfigured();
    }

    /**
     * Draft from a sentence — assist 1.
     *
     * "Users can't export the March payroll as PDF on Safari" becomes a Bug with a title, a
     * description in the house template, a suggested priority and up to two labels. Returned as
     * form state; nothing is saved.
     *
     * @return array{title: string, description: string, type: string, priority: string, labels: array<int, string>}|null null when the model is off or did not answer
     */
    public function draft(string $sentence, ?Project $project = null): ?array
    {
        $types = array_values(options('work.type'));
        $priorities = array_values(options('work.priority'));

        $system = implode("\n", [
            'You turn one sentence from a software or services team into a work item for their board.',
            'Answer only with the fields asked for. Do not invent facts that are not in the sentence; where the',
            'sentence does not say, leave the template line as a short placeholder in square brackets.',
            'The description is Markdown in this house template, exactly these three headings:',
            '## Steps', '## Expected', '## Actual',
            'For something that is not a bug, use "## Why", "## What" and "## Done when" instead.',
            'Allowed types: '.implode(', ', $types).'. Allowed priorities: '.implode(', ', $priorities).'.',
            'Labels are one or two short lowercase words naming the area, e.g. "export", "safari".',
            $project !== null ? 'The project is "'.$project->name.'".' : '',
        ]);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'description', 'type', 'priority', 'labels'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Under 80 characters, imperative or a plain statement of the fault.'],
                'description' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => $types],
                'priority' => ['type' => 'string', 'enum' => $priorities],
                'labels' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string']],
            ],
        ];

        $reply = $this->ask($system, $sentence, $schema);

        if ($reply === null || blank($reply['title'] ?? null)) {
            return null;
        }

        return [
            'title' => mb_substr(trim((string) $reply['title']), 0, 255),
            'description' => trim((string) ($reply['description'] ?? '')),
            'type' => in_array($reply['type'] ?? null, $types, true) ? $reply['type'] : ($types[2] ?? 'Task'),
            'priority' => in_array($reply['priority'] ?? null, $priorities, true) ? $reply['priority'] : 'Medium',
            'labels' => array_values(array_slice(array_filter(array_map(fn ($l): string => Str::limit(Str::lower(trim((string) $l)), 40, ''), (array) ($reply['labels'] ?? []))), 0, 2)),
        ];
    }

    /** Whether the thread is long enough that a summary is offered — assist 2. */
    public function canSummarise(WorkItem $item): bool
    {
        return $this->isAvailable() && $item->comments()->count() > self::SUMMARY_FROM;
    }

    /**
     * Summarise the thread — assist 2. Three lines, stored and stamped, regenerated on request.
     *
     * @return string|null the summary, or null when the model is off or did not answer
     */
    public function summarise(WorkItem $item): ?string
    {
        $comments = $item->comments()->orderBy('id')->get();

        $thread = $comments->map(function (Comment $c): string {
            $who = $c->user?->name ?? 'Somebody';

            return '['.$c->created_at?->format('d M').'] '.$who.': '.Str::limit(trim((string) $c->body), 600);
        })->implode("\n");

        $system = implode("\n", [
            'Summarise a discussion thread on a work item for somebody who has not read it.',
            'Exactly three lines, plain text, no bullets, no preamble: what was decided, what is still open, and who is waiting on whom.',
            'Say nothing the thread does not say. Name people as the thread names them.',
            'The item is '.$item->key.': '.$item->title.'.',
        ]);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary'],
            'properties' => ['summary' => ['type' => 'string']],
        ];

        $reply = $this->ask($system, $thread, $schema);

        if ($reply === null || blank($reply['summary'] ?? null)) {
            return null;
        }

        $summary = mb_substr(trim((string) $reply['summary']), 0, 1000);

        $item->forceFill(['ai_summary' => $summary, 'ai_summary_at' => now()])->saveQuietly();

        return $summary;
    }

    /**
     * Suggest the estimate — assist 3.
     *
     * From the title, the type and the estimates of the project's closed items with similar
     * titles: a number next to the field, with the three items it was drawn from. The number is
     * never written; the person types it or does not. The similar items are found here, by the
     * words they share, so the model reasons over three real neighbours rather than guessing from
     * the title alone.
     *
     * @return array{unit: string, estimate: float|int, reason: string, drawn_from: array<int, array{key: string, title: string, estimate: float|int}>}|null
     */
    public function estimate(WorkItem $item): ?array
    {
        $unit = (string) setting('work.estimation_unit', 'hours');
        $column = $unit === 'points' ? 'estimate_points' : 'estimate_minutes';

        $neighbours = $this->similarClosedItems($item, $column);

        if ($neighbours->isEmpty()) {
            return null;
        }

        $drawnFrom = $neighbours->map(fn (WorkItem $n): array => [
            'key' => $n->key,
            'title' => $n->title,
            'estimate' => $unit === 'points' ? (float) $n->estimate_points : (int) $n->estimate_minutes,
        ])->values()->all();

        $system = implode("\n", [
            'Suggest an estimate for a work item from three finished items on the same project with similar titles.',
            'Unit: '.($unit === 'points' ? 'story points, one decimal at most' : 'minutes, a whole number').'.',
            'Lean on the neighbours: a new item like three that took two hours each is about two hours. Give one short reason.',
        ]);

        $utterance = 'New item ('.$item->type.'): '.$item->title."\n\nFinished neighbours:\n"
            .collect($drawnFrom)->map(fn (array $n): string => '- '.$n['key'].' '.$n['title'].' — '.$n['estimate'].' '.$unit)->implode("\n");

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['estimate', 'reason'],
            'properties' => [
                'estimate' => ['type' => 'number', 'minimum' => 0],
                'reason' => ['type' => 'string'],
            ],
        ];

        $reply = $this->ask($system, $utterance, $schema);

        if ($reply === null || ! is_numeric($reply['estimate'] ?? null)) {
            return null;
        }

        return [
            'unit' => $unit,
            'estimate' => $unit === 'points' ? round((float) $reply['estimate'], 1) : (int) round((float) $reply['estimate']),
            'reason' => Str::limit(trim((string) ($reply['reason'] ?? '')), 300),
            'drawn_from' => $drawnFrom,
        ];
    }

    /**
     * The three closed items of the project whose titles share the most words with this one.
     *
     * @return Collection<int, WorkItem>
     */
    public function similarClosedItems(WorkItem $item, string $column, int $take = 3): Collection
    {
        $words = $this->words($item->title);

        if ($words === []) {
            return collect();
        }

        return WorkItem::query()
            ->where('project_id', $item->project_id)
            ->whereKeyNot($item->getKey())
            ->whereNotNull($column)
            ->whereHas('status', fn ($s) => $s->where('category', WorkStatus::DONE))
            ->get()
            ->map(fn (WorkItem $n): array => ['item' => $n, 'score' => count(array_intersect($words, $this->words($n->title)))])
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($take)
            ->map(fn (array $row): WorkItem => $row['item'])
            ->values();
    }

    /** @return array<int, string> */
    private function words(string $title): array
    {
        $stop = ['the', 'a', 'an', 'on', 'in', 'of', 'to', 'for', 'and', 'or', 'is', 'are', 'not', 'when', 'with', 'from', 'at', 'by', 'as', 'it', 'this', 'that'];

        return array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($title))) ?: [],
            fn (string $w): bool => strlen($w) > 2 && ! in_array($w, $stop, true),
        )));
    }

    /**
     * One call, or null. A model that is off or did not answer is no assist, never an error on the
     * screen: the person does by hand what they would have done anyway.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function ask(string $system, string $utterance, array $schema): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            return $this->model->extract($system, $utterance, $schema);
        } catch (ModelUnavailable $e) {
            report($e);

            return null;
        }
    }
}
