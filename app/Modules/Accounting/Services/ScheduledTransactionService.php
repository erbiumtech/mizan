<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\ScheduledTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Raising the entries a schedule is due for.
 *
 * The two properties that matter, both of which are easy to lose:
 *
 *  - IT NEVER RAISES THE SAME OCCURRENCE TWICE. Idempotency is checked against
 *    the ledger itself — an entry carrying this schedule's source and that date
 *    already exists or it does not — rather than against a "last run" column on
 *    the schedule. A counter is wrong the moment somebody deletes a draft, runs
 *    the command by hand, or restores a backup.
 *
 *  - IT CATCHES UP. Cron does not run for a week and the rent still gets raised
 *    for that week, because due dates are derived from the schedule's own start
 *    rather than from when the job last happened to fire.
 */
class ScheduledTransactionService
{
    /**
     * How many occurrences one schedule may raise in a single run.
     *
     * Catch-up is the point, but a start date typed as 2016 instead of 2026 would
     * otherwise put 120 drafts in the ledger before anybody saw it. What is left
     * over is not lost: the next run raises the next batch, because "already
     * raised" is asked of the ledger and not of a cursor.
     */
    public const MAX_PER_RUN = 24;

    public function __construct(
        private JournalEntryService $entries,
        private SecondApproverRule $secondApprover,
    ) {}

    /**
     * Approve without naming an approver, then post.
     *
     * Mirrors PendingPayrollPoster::approveAsSystem(): stamping some person as
     * `approved_by` would record an approval nobody gave, so the column stays
     * null and the entry says it was posted by the system.
     */
    private function postAsSystem(JournalEntry $entry): JournalEntry
    {
        $entry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        return $this->entries->post($entry->fresh());
    }

    /**
     * Schedules with at least one occurrence outstanding on or before $upTo.
     *
     * @return Collection<int, ScheduledTransaction>
     */
    public function due(?CarbonImmutable $upTo = null): Collection
    {
        $upTo ??= CarbonImmutable::now()->startOfDay();

        return ScheduledTransaction::active()
            ->with('lines.account')
            ->get()
            ->filter(fn (ScheduledTransaction $schedule): bool => $this->outstandingFor($schedule, $upTo) !== []);
    }

    /**
     * The dates this schedule owes an entry for, oldest first, capped.
     *
     * @return array<int, CarbonImmutable>
     */
    public function outstandingFor(ScheduledTransaction $schedule, ?CarbonImmutable $upTo = null): array
    {
        $upTo ??= CarbonImmutable::now()->startOfDay();

        $already = JournalEntry::query()
            ->forSource(ScheduledTransaction::class, $schedule->getKey())
            ->pluck('entry_date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->all();

        $outstanding = array_values(array_filter(
            $schedule->occurrencesUpTo($upTo),
            fn (CarbonImmutable $date): bool => ! in_array($date->toDateString(), $already, true),
        ));

        return array_slice($outstanding, 0, self::MAX_PER_RUN);
    }

    /**
     * Every occurrence of every active schedule inside a window, raised or not.
     *
     * **Forward-looking, and uncapped, which is what makes it different from `due()`/`outstandingFor()`
     * above.** Those two answer a *posting run*: only what is outstanding now, and at most
     * `MAX_PER_RUN` of it, because a run that raised two hundred back-dated entries in one go is a run
     * nobody can review. Neither limit belongs in a report — the cap would silently shorten a ninety-day
     * view of a weekly schedule, and "outstanding only" would hide the occurrences that have not come round
     * yet, which are the whole point of looking forward.
     *
     * Written for the cash-commitments report, `docs/reports-expansion-plan.md` Phase 1.7.
     *
     * @return array<int, array{schedule: ScheduledTransaction, date: CarbonImmutable, raised: bool}>
     */
    public function occurrencesBetween(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $schedules = ScheduledTransaction::active()->with('lines')->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        // One query for every schedule's raised dates rather than one per schedule: this is a report over
        // the whole book, and `outstandingFor()` asks per schedule because it is called for one.
        $raised = JournalEntry::query()
            // Through the scope, not a literal alias: `source_type` holds a stable token rather than a
            // class name, and one place in this application knows how to turn one into the other.
            ->forSource(ScheduledTransaction::class)
            ->whereIn('source_id', $schedules->modelKeys())
            ->get(['source_id', 'entry_date'])
            ->groupBy('source_id')
            ->map(fn ($rows): array => $rows
                ->map(fn ($row): string => CarbonImmutable::parse($row->entry_date)->toDateString())
                ->all());

        $rows = [];

        foreach ($schedules as $schedule) {
            $already = $raised->get($schedule->getKey(), []);

            foreach ($schedule->occurrencesUpTo($end) as $date) {
                if ($date->lessThan($start)) {
                    continue;
                }

                $rows[] = [
                    'schedule' => $schedule,
                    'date' => $date,
                    'raised' => in_array($date->toDateString(), $already, true),
                ];
            }
        }

        return $rows;
    }

    /**
     * Raise every outstanding entry for every active schedule.
     *
     * @return Collection<int, JournalEntry>
     */
    public function run(?CarbonImmutable $upTo = null): Collection
    {
        $upTo ??= CarbonImmutable::now()->startOfDay();
        $raised = collect();

        foreach ($this->due($upTo) as $schedule) {
            foreach ($this->outstandingFor($schedule, $upTo) as $date) {
                $entry = $this->raise($schedule, $date);

                if ($entry !== null) {
                    $raised->push($entry);
                }
            }
        }

        return $raised;
    }

    /**
     * One draft entry for one occurrence, or null if the schedule cannot produce
     * a valid one.
     *
     * A schedule whose lines do not balance is skipped rather than allowed to
     * throw: one broken schedule must not stop the other eleven from being
     * raised, and the reason is on the schedule's own screen where somebody can
     * act on it. The form refuses to save an unbalanced schedule in the first
     * place — this is the second line, for a schedule broken later by an account
     * being deactivated underneath it.
     */
    public function raise(ScheduledTransaction $schedule, CarbonImmutable $date): ?JournalEntry
    {
        $lines = $schedule->lines
            ->map(fn ($line): array => array_filter([
                'account_id' => $line->account_id,
                'debit_amount' => (float) $line->debit_amount ?: null,
                'credit_amount' => (float) $line->credit_amount ?: null,
                'description' => $line->description,
            ], fn ($value): bool => $value !== null))
            ->all();

        if (count($lines) < 2 || ! $schedule->isBalanced()) {
            return null;
        }

        try {
            $entry = $this->entries->create(
                [
                    'entry_date' => $date->toDateString(),
                    'entry_type' => $schedule->entry_type,
                    'reference' => $schedule->reference,
                    'memo' => $schedule->memo ?: $schedule->name,
                    'source_type' => ScheduledTransaction::class,
                    'source_id' => $schedule->getKey(),
                ],
                $lines,
            );

            // A draft is the right output where somebody will read it before it
            // reaches the books. Where the company has said there is no second
            // approver, nobody will — the draft would wait for a person who does
            // not exist while the rent it describes has already left the bank.
            // Posted with no approver named, which is the honest record: the
            // system posted this under the company's own policy.
            return $this->secondApprover->isRequired()
                ? $entry
                : $this->postAsSystem($entry);
        } catch (\Throwable) {
            // An account switched to "no manual entry", or a closed fiscal year.
            // Both are real states, and neither is a reason to abandon the rest
            // of the run.
            return null;
        }
    }
}
