<?php

namespace App\Modules\Performance\Services;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\MeritEntry;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Writing and reading the ledger — docs/saas-launch-plan.md §8.1.
 *
 * Every writer goes through record(); every correction through reverse(). The
 * balance is a sum over rows, asked for a period, and that is all a reader ever
 * gets: nothing here turns points into a rating.
 */
class MeritLedger
{
    public function record(
        Employee|int $employee,
        int $points,
        string $reason,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?User $by = null,
        ?string $occurredOn = null,
    ): MeritEntry {
        if ($points === 0) {
            throw new InvalidArgumentException('A merit entry carries points; zero is not evidence of anything.');
        }

        return MeritEntry::create([
            'employee_id' => $employee instanceof Employee ? $employee->getKey() : $employee,
            'occurred_on' => $occurredOn ?? now()->toDateString(),
            'points' => $points,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason' => $reason,
            'recorded_by' => $by?->getKey(),
        ]);
    }

    /**
     * The correction: a new entry of the opposite sign that names the one it
     * reverses. Once per entry, and never of a reversal — "why was this
     * reversed" has to have one answer in the data.
     */
    public function reverse(MeritEntry $entry, string $reason, ?User $by = null): MeritEntry
    {
        if ($entry->isReversal()) {
            throw new InvalidArgumentException('A reversal is not itself reversed; record a fresh entry instead.');
        }

        if (MeritEntry::query()->where('reverses_entry_id', $entry->getKey())->exists()) {
            throw new InvalidArgumentException('This entry has already been reversed.');
        }

        return MeritEntry::create([
            'employee_id' => $entry->employee_id,
            'occurred_on' => now()->toDateString(),
            'points' => -$entry->points,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'reason' => $reason,
            'recorded_by' => $by?->getKey(),
            'reverses_entry_id' => $entry->getKey(),
        ]);
    }

    public function hasEntryFor(string $sourceType, int $sourceId): bool
    {
        return MeritEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->exists();
    }

    public function balance(Employee|int $employee, string $from, string $to): int
    {
        return (int) MeritEntry::query()->forEmployee($employee)->between($from, $to)->sum('points');
    }

    /**
     * The period's ledger in one read: what a review form or a portal page shows.
     *
     * @return array{credits: int, debits: int, balance: int, entries: Collection<int, MeritEntry>}
     */
    public function summary(Employee|int $employee, string $from, string $to): array
    {
        $entries = MeritEntry::query()->forEmployee($employee)->between($from, $to)->orderBy('occurred_on')->orderBy('id')->get();

        return [
            'credits' => (int) $entries->where('points', '>', 0)->sum('points'),
            'debits' => (int) $entries->where('points', '<', 0)->sum('points'),
            'balance' => (int) $entries->sum('points'),
            'entries' => $entries,
        ];
    }
}
