<?php

namespace App\Modules\Lifecycle\Support;

use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeChecklistItem;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Onboarding and offboarding progress — `docs/reports-expansion-plan.md` Phase 3.9.
 *
 * "Checklist items overdue by owner role, from `employee_checklist_items.due_on`."
 *
 * **Read as a progress report rather than only an overdue list**, which is what the plan's own title asks for.
 * Every outstanding item is listed and the overdue ones sort to the top, because a checklist that is merely
 * *behind* is a different thing from one that is not started, and an overdue-only list cannot tell them apart.
 *
 * **"By owner role" is delivered in the note and in the ordering, not by making roles the rows.** Grouping the
 * rows by role would answer "whose queue is longest" and lose which task, for whom — and somebody acting on
 * this report needs to know that it is the laptop for the new starter in accounts. So the note carries the
 * per-role counts, the worst role's items sort first, and every row names its role.
 *
 * **Three findings, and two of them are items no overdue report can ever show.**
 *
 *  - **No due date.** `due_on` is nullable, so an item without one can never *become* overdue. It will sit
 *    outstanding forever and nothing will ever chase it — which makes it more dangerous than a late item, not
 *    less, and it is invisible to exactly the report somebody would look for it on.
 *  - **No owner role.** Also nullable, and `ChecklistItem` already names the worry: a template "pointing at
 *    somebody who left is a checklist nobody owns". An item with no role is one nobody has been asked to do.
 *  - **An exit item still open for somebody who has already left.** The one with a security edge: exit
 *    checklists are where access cards, accounts and keys get revoked, so an open exit item for a former
 *    employee is a door still unlocked. Ties to Phase 3.7, which reports the kit they walked out with.
 */
class ChecklistReports
{
    use ReportShapes;

    private const OVERDUE = 0;

    private const NO_DUE_DATE = 1;

    private const NOT_YET_DUE = 2;

    public function checklistProgress(string $asOf): array
    {
        $on = Carbon::parse($asOf);

        /** @var Collection<int, EmployeeChecklistItem> $items */
        $items = EmployeeChecklistItem::query()
            ->outstanding()
            ->with('checklist.employee.user')
            ->get();

        if ($items->isEmpty()) {
            return $this->emptyProgress($asOf);
        }

        $rows = [];
        $overdueByRole = [];
        $overdue = 0;
        $undated = 0;
        $unowned = 0;
        $afterLeaving = 0;
        $worst = 0;

        foreach ($items as $item) {
            $standing = $this->standing($item, $on);
            $employee = $item->checklist?->employee;
            $left = $this->hadLeft($item, $on);

            $rows[] = ['rank' => $standing['rank'], 'role' => $item->owner_role ?: 'Nobody',
                'days' => $standing['days'] ?? 0, 'due' => $item->due_on?->toDateString() ?? '', 'cells' => [
                    (string) ($employee?->display_label ?? 'Employee #'.($item->checklist?->employee_id ?? '—'))
                        .($left ? ' · LEFT' : ''),
                    (string) $this->kindLabel($item->checklist?->kind),
                    (string) $item->title,
                    // Named rather than blanked, because an item nobody owns is the finding and an empty cell
                    // reads as a rendering gap.
                    (string) ($item->owner_role ?: 'Nobody'),
                    (string) ($item->due_on?->toDateString() ?? '—'),
                    $standing['days'] === null ? '—' : number_format($standing['days']),
                    $standing['label'],
                ]];

            if ($standing['rank'] === self::OVERDUE) {
                $overdue++;
                $role = $item->owner_role ?: 'Nobody';
                $overdueByRole[$role] = ($overdueByRole[$role] ?? 0) + 1;
                $worst = max($worst, $standing['days'] ?? 0);
            }

            $undated += $item->due_on === null ? 1 : 0;
            $unowned += ($item->owner_role ?: '') === '' ? 1 : 0;
            $afterLeaving += $left && $item->checklist?->kind === ChecklistTemplate::KIND_EXIT ? 1 : 0;
        }

        $rows = $this->ordered($rows, $overdueByRole);
        $done = $this->completedOnTheseChecklists($items);

        return $this->table(
            'ChecklistProgress',
            'Onboarding / Offboarding Progress',
            $this->subtitle('outstanding as at '.$asOf),
            ['Employee', 'Checklist', 'Item', 'Owner role', 'Due', 'Days late', 'Standing'],
            'minmax(0, 12rem) 9rem minmax(0, 1fr) 10rem 8rem 8rem 12rem',
            [5],
            $rows,
            [
                ['label' => 'OVERDUE', 'value' => (float) $overdue, 'accent' => true],
                ['label' => 'OUTSTANDING', 'value' => (float) $items->count(), 'accent' => false],
            ],
            $this->progressNote($items, $done, $overdue, $worst, $overdueByRole, $undated, $unowned, $afterLeaving),
            $rows === [] ? null : [
                'Total — '.count($rows).' items outstanding',
                '',
                '',
                '',
                '',
                $worst > 0 ? 'worst '.number_format($worst) : '',
                $overdue > 0 ? number_format($overdue).' overdue' : 'none overdue',
            ],
            'Every checklist item is done.',
        );
    }

    /**
     * Where an outstanding item stands as at the date being read.
     *
     * Three answers, and the middle one is the finding: an item with no due date cannot become overdue, so it
     * is not "not yet due" — it is never due. Calling it *No due date* rather than leaving it blank is the
     * only way it appears on a report somebody reads for lateness.
     *
     * @return array{rank: int, days: ?int, label: string}
     */
    private function standing(EmployeeChecklistItem $item, Carbon $on): array
    {
        if ($item->due_on === null) {
            return ['rank' => self::NO_DUE_DATE, 'days' => null, 'label' => 'No due date'];
        }

        // Date strings, for the reason the headcount report gives at length: `due_on` is a date cast and the
        // date being read need not be, and one time component is all it takes to be wrong on the due date.
        $due = $item->due_on->toDateString();
        $today = $on->toDateString();

        if ($due >= $today) {
            return ['rank' => self::NOT_YET_DUE, 'days' => null, 'label' => 'Not yet due'];
        }

        return [
            'rank' => self::OVERDUE,
            'days' => (int) $item->due_on->startOfDay()->diffInDays($on->copy()->startOfDay()),
            'label' => 'Overdue',
        ];
    }

    /**
     * Whether the person had left by the date being read.
     *
     * Strictly before, so somebody's last day is not yet a leaver — the same reading
     * `HeadcountReports::headcountAt()` uses and that Phase 3.7 was corrected to. Three reports agreeing on
     * what `left_on` means is worth more than each choosing for itself.
     */
    private function hadLeft(EmployeeChecklistItem $item, Carbon $on): bool
    {
        $leftOn = $item->checklist?->employee?->left_on;

        return $leftOn !== null && $leftOn->toDateString() < $on->toDateString();
    }

    /** Onboarding or exit, spelled the way the checklist kinds read. */
    private function kindLabel(?string $kind): string
    {
        return match ($kind) {
            ChecklistTemplate::KIND_ONBOARDING => 'Onboarding',
            ChecklistTemplate::KIND_EXIT => 'Exit',
            null => 'Unknown',
            default => (string) str($kind)->replace('_', ' ')->title(),
        };
    }

    /**
     * Rows in the order they need acting on, worst-blocked role first.
     *
     * Overdue before undated before not-yet-due; and *within* overdue, the items belonging to the role with
     * the most overdue work come first. That last part is what "by owner role" buys without making roles the
     * rows: whoever is holding up the most appears at the top of the report rather than scattered through it.
     *
     * Each row carries its own sort key rather than being matched back to the item collection by position —
     * the positional form worked and was one inserted filter away from silently pairing a key with the wrong
     * row. The queue size cannot be known until every item has been seen, so it is read at sort time.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $overdueByRole
     * @return array<int, array<int, string>>
     */
    private function ordered(array $rows, array $overdueByRole): array
    {
        $queue = fn (array $row): int => $row['rank'] === self::OVERDUE
            ? ($overdueByRole[$row['role']] ?? 0)
            : 0;

        usort($rows, fn (array $a, array $b): int => [
            $a['rank'], -$queue($a), -$a['days'], $a['due'],
        ] <=> [
            $b['rank'], -$queue($b), -$b['days'], $b['due'],
        ]);

        return array_map(fn (array $row): array => $row['cells'], $rows);
    }

    /**
     * How much of these checklists is already done.
     *
     * Scoped to the checklists that still have outstanding work, not to every checklist ever run. A completion
     * figure diluted by years of finished onboardings would sit near 100% permanently and tell nobody
     * anything; against the live ones it moves.
     *
     * @param  Collection<int, EmployeeChecklistItem>  $items
     */
    private function completedOnTheseChecklists(Collection $items): int
    {
        $ids = $items->pluck('employee_checklist_id')->unique()->values()->all();

        return $ids === [] ? 0 : EmployeeChecklistItem::query()
            ->whereIn('employee_checklist_id', $ids)
            ->whereNotNull('completed_at')
            ->count();
    }

    /**
     * What is outstanding, and whose.
     *
     * Progress first, so a reader knows whether they are looking at a checklist that is nearly done or one
     * nobody has started. Then the overdue count with its per-role split, which is the plan's ask. Then the
     * three findings, exit-after-leaving last because it is the rarest and the one worth ending on.
     *
     * @param  Collection<int, EmployeeChecklistItem>  $items
     * @param  array<string, int>  $overdueByRole
     */
    private function progressNote(
        Collection $items,
        int $done,
        int $overdue,
        int $worst,
        array $overdueByRole,
        int $undated,
        int $unowned,
        int $afterLeaving,
    ): string {
        arsort($overdueByRole);

        return mb_strtoupper(implode(' · ', array_filter([
            $done.' of '.($done + $items->count()).' done',
            $overdue > 0
                ? $overdue.' overdue, worst by '.$worst.' days ('.$this->roleSplit($overdueByRole).')'
                : 'nothing is overdue',
            $undated > 0
                ? $undated.' with no due date, so nothing will ever chase '.($undated === 1 ? 'it' : 'them')
                : null,
            $unowned > 0
                ? $unowned.' owned by nobody'
                : null,
            $afterLeaving > 0
                ? $afterLeaving.' exit item'.($afterLeaving === 1 ? '' : 's').' still open for somebody who has left'
                : null,
        ])));
    }

    /**
     * The per-role split, biggest queue first, at most three roles named.
     *
     * Three because the note is one line and a company with nine roles would push everything after it off the
     * end. The rest are counted rather than dropped silently — a truncated list that does not say it is
     * truncated reads as the whole answer.
     *
     * @param  array<string, int>  $overdueByRole
     */
    private function roleSplit(array $overdueByRole): string
    {
        $named = array_slice($overdueByRole, 0, 3, true);
        $rest = count($overdueByRole) - count($named);

        $parts = [];

        foreach ($named as $role => $count) {
            $parts[] = $role.' '.$count;
        }

        if ($rest > 0) {
            $parts[] = 'and '.$rest.' more role'.($rest === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }

    /** @return array<string, mixed> */
    private function emptyProgress(string $asOf): array
    {
        return $this->table(
            'ChecklistProgress',
            'Onboarding / Offboarding Progress',
            $this->subtitle('outstanding as at '.$asOf),
            ['Employee', 'Checklist', 'Item', 'Owner role', 'Due', 'Days late', 'Standing'],
            'minmax(0, 12rem) 9rem minmax(0, 1fr) 10rem 8rem 8rem 12rem',
            [5],
            [],
            [
                ['label' => 'OVERDUE', 'value' => 0.0, 'accent' => true],
                ['label' => 'OUTSTANDING', 'value' => 0.0, 'accent' => false],
            ],
            'EVERY CHECKLIST ITEM IS DONE',
            null,
            'Every checklist item is done.',
        );
    }
}
