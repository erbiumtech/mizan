<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\TimesheetService;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkSprint;
use App\Support\Contracts\LabourCost;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Time on a work item, and what it cost — docs/project-management-plan.md §9.
 *
 * **An hour exists in the ledger exactly once, and the ledger is the timesheet.** Logging time
 * from an item writes a `timesheet_entries` row and nothing else, so the week's timesheet, the
 * payroll inputs and the invoicing lines all see it as time, because it is. Nothing here keeps a
 * second total.
 *
 * Cost is the `LabourCost` contract over the entries — the rate the module behind that contract
 * already knows — rolled up to Epic, sprint and project. A company without payroll gets null and
 * the figure reads as uncosted rather than as nought.
 */
class WorkTime
{
    public const MANUAL = 'manual';

    public const CLOCKIFY = 'clockify';

    public const SMART_COMMIT = 'smart_commit';

    /** Where a row came from, as a person reads it. */
    public const SOURCES = [
        self::MANUAL => 'Logged here',
        self::CLOCKIFY => 'Clockify',
        self::SMART_COMMIT => 'A commit message',
    ];

    public function __construct(
        private readonly TimesheetService $timesheets,
        private readonly LabourCost $cost,
        private readonly EmployeeAccess $access,
    ) {}

    /** Whether time can be logged at all: the timesheet module has to be there to hold it. */
    public function isAvailable(): bool
    {
        return modules()->enabled('timesheets');
    }

    /**
     * Log time against an item, by the person logging it.
     *
     * The employee is the user's own record, not a picker: time logged from a card is *my* time,
     * and a manager logging somebody else's does it on the timesheet, where the picker already is.
     *
     * @throws RuntimeException
     */
    public function log(WorkItem $item, User $by, int $minutes, string $on, ?string $note = null, bool $billable = true): TimesheetEntry
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('There is no timesheet to put this hour in. The Timesheets module is not enabled.');
        }

        $employeeId = $this->access->ownEmployeeId($by);

        if ($employeeId === null) {
            throw new RuntimeException('Your account is not linked to an employee, so there is nobody to book this time to.');
        }

        $entry = new TimesheetEntry([
            'employee_id' => $employeeId,
            'project_id' => $item->project_id,
            'date' => $on,
            'minutes' => $minutes,
            'is_billable' => $billable,
            'task' => $item->key,
            'description' => $note,
        ]);

        // Not fillable on the timesheet's own model, because Timesheets never learns these names.
        $entry->forceFill(['work_item_id' => $item->getKey(), 'source' => self::MANUAL]);

        try {
            return $this->timesheets->book($entry);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
    }

    /** The entries logged against one item, newest first, with who logged them. */
    public function entriesFor(WorkItem $item): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        return TimesheetEntry::query()
            ->where('work_item_id', $item->getKey())
            ->with('employee.user')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Estimate against logged for one item — §9's "the item shows estimate against logged".
     *
     * Remaining is computed and may be negative, which is a finding rather than an error: an item
     * that has taken twice its estimate is the one the standup should hear about.
     *
     * @return array{estimate: ?int, logged: int, remaining: ?int, cost: ?float, uncosted: int, entries: int}
     */
    public function summaryFor(WorkItem $item): array
    {
        return $this->summarise($this->entriesFor($item), $item->estimate_minutes);
    }

    /**
     * The same, in sum, for a sprint — every entry against every item in it.
     *
     * @return array{estimate: ?int, logged: int, remaining: ?int, cost: ?float, uncosted: int, entries: int}
     */
    public function summaryForSprint(WorkSprint $sprint): array
    {
        $items = WorkItem::query()->where('sprint_id', $sprint->getKey())->get();

        return $this->summariseItems($items);
    }

    /**
     * For an Epic: its own time plus its children's, because the Epic is what was promised.
     *
     * @return array{estimate: ?int, logged: int, remaining: ?int, cost: ?float, uncosted: int, entries: int}
     */
    public function summaryForEpic(WorkItem $epic): array
    {
        $items = WorkItem::query()->where('parent_id', $epic->getKey())->get()->push($epic);

        return $this->summariseItems($items);
    }

    /**
     * One figure per row, from the rate the payroll module knows for that employee in that month.
     *
     * Null for a row nothing can price, and the count of those is reported beside the total so a
     * cost of 40,000 over sixty hours with twenty of them uncosted is not read as the cost of sixty.
     */
    public function costOf(TimesheetEntry $entry): ?float
    {
        $rate = $this->cost->hourlyFor($entry->employee_id, $entry->date->toDateString());

        return $rate === null ? null : round($rate * $entry->minutes / 60, 2);
    }

    /** @param  Collection<int, WorkItem>  $items */
    private function summariseItems(Collection $items): array
    {
        if (! $this->isAvailable() || $items->isEmpty()) {
            return $this->summarise(collect(), $this->estimateOf($items));
        }

        $entries = TimesheetEntry::query()
            ->whereIn('work_item_id', $items->modelKeys())
            ->get();

        return $this->summarise($entries, $this->estimateOf($items));
    }

    /** @param  Collection<int, WorkItem>  $items */
    private function estimateOf(Collection $items): ?int
    {
        $estimated = $items->whereNotNull('estimate_minutes');

        return $estimated->isEmpty() ? null : (int) $estimated->sum('estimate_minutes');
    }

    /** @param  Collection<int, TimesheetEntry>  $entries */
    private function summarise(Collection $entries, ?int $estimate): array
    {
        $logged = (int) $entries->sum('minutes');
        $cost = 0.0;
        $uncosted = 0;
        $costed = false;

        foreach ($entries as $entry) {
            $each = $this->costOf($entry);

            if ($each === null) {
                $uncosted++;

                continue;
            }

            $cost += $each;
            $costed = true;
        }

        return [
            'estimate' => $estimate,
            'logged' => $logged,
            'remaining' => $estimate === null ? null : $estimate - $logged,
            'cost' => $costed ? round($cost, 2) : null,
            'uncosted' => $uncosted,
            'entries' => $entries->count(),
        ];
    }

    /** Minutes as a person reads them: `1h 30m`, or a dash for nothing. */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $sign = $minutes < 0 ? '−' : '';
        $minutes = abs($minutes);

        return $sign.intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /** Rows that may be edited on the timesheet: the ones made there. */
    public static function isEditable(TimesheetEntry $entry): bool
    {
        return (string) ($entry->getAttribute('source') ?? self::MANUAL) === self::MANUAL;
    }

    /** Items of a project with a board, for the timesheet's picker. */
    public function pickableItems(int|string|null $projectId, ?int $keep = null): array
    {
        if ($projectId === null) {
            return [];
        }

        return WorkItem::query()
            ->where('project_id', $projectId)
            ->where(fn (Builder $q) => $q->open()->when($keep, fn (Builder $qq, int $id) => $qq->orWhereKey($id)))
            ->orderBy('rank')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (WorkItem $item): array => [$item->getKey() => $item->display_label])
            ->all();
    }
}
