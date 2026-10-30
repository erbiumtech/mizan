<?php

namespace App\Modules\Work\Services;

use App\Modules\Work\Events\ReleaseReleased;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkRelease;
use App\Modules\Work\Models\WorkStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Shipping a release, and the notes that go out with it.
 *
 * **Releasing stamps a date and moves nothing** — docs/project-management-plan.md §6.4. The items
 * stay where they are, because "it shipped" and "it is done" are different facts: an item can ship
 * behind a flag and still be open. What releasing does refuse is to go out quietly with open work
 * in it — that is asked about, the same way completing a sprint asks.
 */
class Releases
{
    /**
     * Mark a release as shipped.
     *
     * With open items still in it, the caller has to say what happens to them: moved to another
     * release, or left where they are with `$keepOpen`. Neither is chosen silently, because a release
     * note that lists a bug as fixed when it is not is the kind of mistake a customer finds first.
     *
     * @param  ?WorkRelease  $moveOpenTo  where the unfinished go
     * @param  bool  $keepOpen  ship with the open items still attributed to this release
     *
     * @throws RuntimeException
     */
    public function release(WorkRelease $release, ?WorkRelease $moveOpenTo = null, bool $keepOpen = false): WorkRelease
    {
        if ($release->isReleased()) {
            throw new RuntimeException($release->name.' went out on '.$release->released_on->format('d M Y').' already.');
        }

        if ($moveOpenTo !== null && (int) $moveOpenTo->getKey() === (int) $release->getKey()) {
            throw new RuntimeException('Open work cannot be moved into the release being shipped.');
        }

        if ($moveOpenTo !== null && $moveOpenTo->isReleased()) {
            throw new RuntimeException('Open work cannot be moved into a release that has already gone out.');
        }

        $open = $this->open($release)->get();

        if ($open->isNotEmpty() && $moveOpenTo === null && ! $keepOpen) {
            throw new RuntimeException($open->count().' item(s) in '.$release->name
                .' are still open. Move them to another release, or say that they ship open.');
        }

        if ($moveOpenTo !== null) {
            foreach ($open as $item) {
                $item->forceFill(['release_id' => $moveOpenTo->getKey()])->save();
            }
        }

        $release->forceFill(['released_on' => today()])->save();

        ReleaseReleased::dispatch($release);

        return $release;
    }

    /** What is still open in a release — the items a ship has to decide about. */
    public function open(WorkRelease $release): Builder
    {
        return WorkItem::query()
            ->where('release_id', $release->getKey())
            ->whereNull('archived_at')
            ->whereHas('status', fn (Builder $s) => $s->where('category', '!=', WorkStatus::DONE));
    }

    /**
     * The release notes, as Markdown: one line per done item, grouped by type.
     *
     * Markdown because that is the text that goes into a GitHub release and a customer email, and
     * Markdown survives both trips. Derived every time rather than stored, so a title corrected after
     * the release is corrected in the notes too.
     *
     * Types are in the company's own order (the option list's), and an item with a type the list no
     * longer has still appears under the type it was filed as — the notes must never drop a line.
     */
    public function notes(WorkRelease $release): string
    {
        $done = WorkItem::query()
            ->where('release_id', $release->getKey())
            ->whereNull('archived_at')
            ->whereHas('status', fn (Builder $s) => $s->where('category', WorkStatus::DONE))
            ->orderBy('type')
            ->orderBy('number')
            ->get();

        $heading = '# '.$release->name.($release->released_on !== null
            ? ' — '.$release->released_on->format('d M Y')
            : '');

        if ($done->isEmpty()) {
            return $heading."\n\n_Nothing finished in this release yet._\n";
        }

        $order = array_map('strtolower', array_keys(options('work.type')));

        $groups = $done
            ->groupBy(fn (WorkItem $item): string => strtolower((string) $item->type))
            ->sortBy(function (Collection $items, string $type) use ($order): int {
                $at = array_search($type, $order, true);

                return $at === false ? count($order) : $at;
            });

        $labels = array_change_key_case(options('work.type'), CASE_LOWER);

        $sections = $groups->map(function (Collection $items, string $type) use ($labels): string {
            $lines = $items->map(fn (WorkItem $item): string => '- '.$item->key.' '.$item->title)->implode("\n");

            return '## '.($labels[$type] ?? ucfirst($type))."\n\n".$lines;
        });

        return $heading."\n\n".$sections->implode("\n\n")."\n";
    }
}
