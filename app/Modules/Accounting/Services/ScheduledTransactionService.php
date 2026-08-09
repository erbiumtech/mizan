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

    public function __construct(private JournalEntryService $entries) {}

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
            return $this->entries->create(
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
        } catch (\Throwable) {
            // An account switched to "no manual entry", or a closed fiscal year.
            // Both are real states, and neither is a reason to abandon the rest
            // of the run.
            return null;
        }
    }
}
