<?php

namespace App\Modules\Performance\Support;

use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\OneToOne;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Collection;

/**
 * Review cycle progress — `docs/reports-expansion-plan.md` Phase 3.12.
 *
 * "Reviews and goals complete per cycle, one-to-ones held."
 *
 * **"Complete" is the whole question, and the model already answers it.** `Review` has a five-rung ladder —
 * pending, self-submitted, manager-submitted, shared, acknowledged — and only the last rung is a review that
 * finished. The one before it is the one that matters: `isVisibleToEmployee()` is `shared_at !== null`, and its
 * docblock says why, that "submitted is not shared" because "a written review is a draft about somebody until
 * a manager decides to share it".
 *
 * **So a closed cycle holding unshared reviews is the report's sharpest finding.** Somebody wrote a review of
 * a person, the cycle was closed, and the person never saw it. The written review exists, the rating may even
 * have been calibrated, and as far as the employee knows the cycle passed them by. Nothing else in the
 * application asks — a review sitting at *manager submitted* looks like work done on every screen there is.
 *
 * **A closed cycle with open goals is the same failure in the other column.** `Goal` has three settled
 * states — achieved, missed, dropped — and *missed* is one of them, so leaving a goal open past the end of
 * its cycle is not a kindness. It is nobody having decided, which means the goal cannot be learned from.
 *
 * **One-to-ones are counted inside the cycle's own period**, not the report's, because the plan's phrase is
 * "one-to-ones held" and what makes that figure mean anything is the span it belongs to. A cycle with none at
 * all is stated: the reviews in it were written without a conversation behind them.
 */
class PerformanceReports
{
    use ReportShapes;

    /** Goal states in which somebody has decided something. `missed` counts — a miss is a decision. */
    private const SETTLED_GOALS = [Goal::STATUS_ACHIEVED, Goal::STATUS_MISSED, Goal::STATUS_DROPPED];

    /**
     * The ledger per employee for the period — docs/saas-launch-plan.md §8.1.
     * Sums over rows, never a stored total.
     */
    public function meritLedger(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $entries = MeritEntry::query()
            ->between($period['from'], $period['to'])
            ->with('employee.user')
            ->get()
            ->groupBy('employee_id');

        $rows = [];
        $credits = 0;
        $debits = 0;

        foreach ($entries as $group) {
            $employee = $group->first()->employee;
            $plus = (int) $group->where('points', '>', 0)->sum('points');
            $minus = (int) $group->where('points', '<', 0)->sum('points');
            $credits += $plus;
            $debits += $minus;

            $rows[] = [
                (string) ($employee?->display_label ?? '—'),
                (string) ($employee?->department ?? '—'),
                $this->count($group->count()),
                $plus === 0 ? '—' : '+'.number_format($plus),
                $minus === 0 ? '—' : number_format($minus),
                number_format($plus + $minus),
            ];
        }

        usort($rows, fn (array $a, array $b): int => (int) str_replace(',', '', $b[5]) <=> (int) str_replace(',', '', $a[5]));

        return $this->table(
            'MeritLedgerReport',
            'Merit Ledger',
            $this->subtitle('entries dated between '.$period['from'].' and '.$period['to']),
            ['Employee', 'Department', 'Entries', 'Credits', 'Debits', 'Balance'],
            'minmax(0, 16rem) 10rem 7rem 7rem 7rem 8rem',
            [2, 3, 4, 5],
            $rows,
            [
                ['label' => 'CREDITS', 'value' => (float) $credits, 'accent' => true],
                ['label' => 'DEBITS', 'value' => (float) $debits, 'accent' => false],
                ['label' => 'EMPLOYEES', 'value' => (float) count($rows), 'accent' => false],
            ],
            'A balance is a sum over dated entries; nothing here is a rating, and nothing turns it into one.',
            empty: 'No merit entries in this period.',
        );
    }

    public function reviewCycleProgress(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $cycles = ReviewCycle::query()
            // Drafts excluded: a cycle nobody opened has no progress, and listing it would put a row of
            // noughts above the cycles somebody is actually running.
            ->whereIn('status', [
                ReviewCycle::STATUS_OPEN,
                ReviewCycle::STATUS_CALIBRATING,
                ReviewCycle::STATUS_CLOSED,
            ])
            // Overlap, not containment. A cycle running July to December belongs in this year's report from
            // the day it starts, and one that ended in June belongs to last year's.
            ->whereDate('period_start', '<=', $period['to'])
            ->whereDate('period_end', '>=', $period['from'])
            ->orderByDesc('period_start')
            ->get();

        if ($cycles->isEmpty()) {
            return $this->emptyProgress($period);
        }

        $reviews = $this->reviewsByCycle($cycles);
        $goals = $this->goalsByCycle($cycles);

        $rows = [];
        $totals = ['reviews' => 0, 'acknowledged' => 0, 'goals' => 0, 'settled' => 0, 'meetings' => 0];
        $unshared = 0;
        $unsettled = 0;
        $withoutMeetings = 0;

        foreach ($cycles as $cycle) {
            $cycleReviews = $reviews->get($cycle->getKey(), collect());
            $cycleGoals = $goals->get($cycle->getKey(), collect());
            $meetings = $this->meetingsInCycle($cycle);

            $acknowledged = $cycleReviews->where('status', Review::STATUS_ACKNOWLEDGED)->count();
            // Read from `shared_at`, not from the status, because that is the column
            // `isVisibleToEmployee()` reads. A status is a label somebody set; the timestamp is what decides
            // whether the person can see their own review.
            $neverShared = $cycleReviews->whereNull('shared_at')->count();
            $openGoals = $cycleGoals->whereNotIn('status', self::SETTLED_GOALS)->count();
            $settled = $cycleGoals->count() - $openGoals;

            $rows[] = [
                (string) $cycle->name,
                $cycle->period_start->toDateString().' – '.$cycle->period_end->toDateString(),
                $this->count($cycleReviews->count()),
                $this->count($acknowledged),
                $this->count($cycleGoals->count()),
                $this->count($settled),
                $this->count($meetings),
                $this->standing($cycle, $neverShared, $openGoals, $meetings, $cycleReviews->count()),
            ];

            $totals['reviews'] += $cycleReviews->count();
            $totals['acknowledged'] += $acknowledged;
            $totals['goals'] += $cycleGoals->count();
            $totals['settled'] += $settled;
            $totals['meetings'] += $meetings;

            if ($cycle->isClosed()) {
                $unshared += $neverShared;
                $unsettled += $openGoals;
            }

            if ($meetings === 0 && $cycleReviews->count() > 0) {
                $withoutMeetings++;
            }
        }

        return $this->table(
            'ReviewCycleProgress',
            'Review Cycle Progress',
            $this->subtitle('cycles running between '.$period['from'].' and '.$period['to']),
            ['Cycle', 'Period', 'Reviews', 'Acknowledged', 'Goals', 'Settled', 'One-to-ones', 'Standing'],
            'minmax(0, 12rem) 15rem 9rem 11rem 8rem 9rem 11rem minmax(12rem, 20rem)',
            [2, 3, 4, 5, 6],
            $rows,
            [
                ['label' => 'ACKNOWLEDGED', 'value' => (float) $totals['acknowledged'], 'accent' => true],
                // The figure nothing else in the application will mention: reviews written about people who
                // never got to read them, in cycles somebody has already closed.
                ['label' => 'NEVER SHARED', 'value' => (float) $unshared, 'accent' => false],
            ],
            $this->progressNote($cycles->count(), $totals, $unshared, $unsettled, $withoutMeetings),
            $rows === [] ? null : [
                'Total — '.count($rows).' cycles',
                '',
                number_format($totals['reviews']),
                number_format($totals['acknowledged']),
                number_format($totals['goals']),
                number_format($totals['settled']),
                number_format($totals['meetings']),
                '',
            ],
            'No review cycle was running in this period.',
            // Eight columns and a standing that carries sentences. Wider than the pane, so it scrolls rather
            // than being silently clipped — Phase 0.2.
            wide: true,
        );
    }

    /**
     * Where the cycle stands, with the findings as suffixes.
     *
     * The status alone says nothing about whether the cycle did its job: *closed* is exactly the state in
     * which an unshared review and an undecided goal stop being work in progress and become work that was
     * abandoned. So both suffixes are raised only on a closed cycle, and the review chase is raised only on
     * one that is still running — the same fact means opposite things at the two ends.
     */
    private function standing(ReviewCycle $cycle, int $neverShared, int $openGoals, int $meetings, int $reviews): string
    {
        $label = match ($cycle->status) {
            ReviewCycle::STATUS_CALIBRATING => 'Calibrating',
            ReviewCycle::STATUS_CLOSED => 'Closed',
            default => 'Open',
        };

        return implode(' · ', array_filter([
            $label,
            $cycle->isClosed() && $neverShared > 0
                ? $neverShared.' never shared'
                : null,
            $cycle->isClosed() && $openGoals > 0
                ? $openGoals.' goals undecided'
                : null,
            // Only where there were reviews. A cycle with no reviews yet has nothing to have talked about.
            $meetings === 0 && $reviews > 0
                ? 'no one-to-ones'
                : null,
        ]));
    }

    /**
     * Reviews per cycle, in one query.
     *
     * @param  Collection<int, ReviewCycle>  $cycles
     * @return Collection<int, Collection<int, Review>>
     */
    private function reviewsByCycle(Collection $cycles): Collection
    {
        return Review::query()
            ->whereIn('review_cycle_id', $cycles->modelKeys())
            ->get(['id', 'review_cycle_id', 'status', 'shared_at'])
            ->groupBy('review_cycle_id');
    }

    /**
     * Goals per cycle, in one query.
     *
     * The `whereIn` is a **narrowing, not a guard** — the grouped result is read by cycle key below, so a goal
     * belonging to some other cycle would be fetched and then never looked up. It is here to avoid pulling
     * every goal the company has ever set. Removing it changes no output, and no test here pretends
     * otherwise. The same is true of `reviewsByCycle()`.
     *
     * A goal's `review_cycle_id` is nullable — a standing objective need not belong to a cycle — and those are
     * simply absent here rather than counted against whichever cycle happens to be open. A cycle is judged on
     * the goals somebody set within it.
     *
     * @param  Collection<int, ReviewCycle>  $cycles
     * @return Collection<int, Collection<int, Goal>>
     */
    private function goalsByCycle(Collection $cycles): Collection
    {
        return Goal::query()
            ->whereIn('review_cycle_id', $cycles->modelKeys())
            ->get(['id', 'review_cycle_id', 'status'])
            ->groupBy('review_cycle_id');
    }

    /**
     * One-to-ones held inside this cycle's own period.
     *
     * Counted per cycle rather than fetched once and split, because `one_to_ones` has no cycle column — the
     * only thing tying a meeting to a cycle is the date falling inside it. Which also means overlapping cycles
     * would each count the same meeting, and that is right: the conversation happened during both.
     */
    private function meetingsInCycle(ReviewCycle $cycle): int
    {
        return OneToOne::query()
            ->whereDate('met_on', '>=', $cycle->period_start->toDateString())
            ->whereDate('met_on', '<=', $cycle->period_end->toDateString())
            ->count();
    }

    /** A zero count is a dash: five count columns of noughts is unreadable, and the footer has the totals. */
    private function count(int $value): string
    {
        return $value === 0 ? '—' : number_format($value);
    }

    /**
     * What the cycles amount to, closure failures first.
     *
     * Unshared reviews lead because they are the only figure here that describes something done *to* somebody
     * without their knowing. The undecided goals follow, and the missing conversations last.
     *
     * @param  array<string, int>  $totals
     */
    private function progressNote(
        int $cycles,
        array $totals,
        int $unshared,
        int $unsettled,
        int $withoutMeetings,
    ): string {
        return mb_strtoupper(implode(' · ', array_filter([
            $cycles.' cycles',
            $totals['acknowledged'].' of '.$totals['reviews'].' reviews acknowledged',
            $totals['settled'].' of '.$totals['goals'].' goals decided',
            // Two whole sentences rather than four inline pluralisations. The clause carries the report's
            // most serious finding and it has to read as English, not as a template.
            match (true) {
                $unshared === 1 => 'one review in a closed cycle was never shared with the person it is about',
                $unshared > 1 => $unshared.' reviews in closed cycles were never shared with the people they are about',
                default => null,
            },
            $unsettled > 0
                ? $unsettled.' goal'.($unsettled === 1 ? '' : 's').' left undecided in closed cycles'
                : null,
            $withoutMeetings > 0
                ? $withoutMeetings.' cycle'.($withoutMeetings === 1 ? '' : 's').' had no one-to-ones at all'
                : null,
            $totals['meetings'].' one-to-ones held',
        ])));
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptyProgress(array $period): array
    {
        return $this->table(
            'ReviewCycleProgress',
            'Review Cycle Progress',
            $this->subtitle('cycles running between '.$period['from'].' and '.$period['to']),
            ['Cycle', 'Period', 'Reviews', 'Acknowledged', 'Goals', 'Settled', 'One-to-ones', 'Standing'],
            'minmax(0, 12rem) 15rem 9rem 11rem 8rem 9rem 11rem minmax(12rem, 20rem)',
            [2, 3, 4, 5, 6],
            [],
            [
                ['label' => 'ACKNOWLEDGED', 'value' => 0.0, 'accent' => true],
                ['label' => 'NEVER SHARED', 'value' => 0.0, 'accent' => false],
            ],
            'NO REVIEW CYCLE WAS RUNNING IN THIS PERIOD',
            null,
            'No review cycle was running in this period.',
            wide: true,
        );
    }

    /**
     * How the ratings fell — docs/saas-launch-plan.md §14.10.
     *
     * One row per cycle per rating, with the share. The shape of the distribution is
     * the point and it is the thing a calibration meeting is about: a cycle where
     * four-fifths of the company scored the same number has not measured anything, and a
     * cycle with nobody at either end has a manager who does not use the ends.
     *
     * **Shared ratings only.** A rating a manager has entered and not yet shared is
     * still being argued about, and counting it would make the distribution move under
     * the people calibrating it.
     *
     * Broken down by department as well, because the two together are what answers "is
     * this a company-wide curve or one manager's".
     */
    public function ratingsDistribution(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);
        $scale = max(2, (int) setting('performance.rating_scale', 5));

        $cycles = ReviewCycle::query()
            ->whereIn('status', [ReviewCycle::STATUS_OPEN, ReviewCycle::STATUS_CALIBRATING, ReviewCycle::STATUS_CLOSED])
            ->whereDate('period_start', '<=', $period['to'])
            ->whereDate('period_end', '>=', $period['from'])
            ->orderByDesc('period_start')
            ->get();

        $reviews = Review::query()
            ->whereIn('review_cycle_id', $cycles->modelKeys())
            ->whereNotNull('final_rating')
            // Shared or acknowledged: a rating still inside the manager's draft is not a
            // rating anybody has been told, and the distribution would move under them.
            ->whereIn('status', [Review::STATUS_SHARED, Review::STATUS_ACKNOWLEDGED])
            ->with('employee')
            ->get();

        $rows = [];
        $rated = 0;

        foreach ($cycles as $cycle) {
            $inCycle = $reviews->where('review_cycle_id', $cycle->getKey());

            if ($inCycle->isEmpty()) {
                continue;
            }

            $rated += $inCycle->count();
            $counts = $inCycle->countBy('final_rating');

            for ($rating = $scale; $rating >= 1; $rating--) {
                $count = (int) ($counts[$rating] ?? 0);

                $rows[] = [
                    (string) $cycle->name,
                    (string) $rating.' of '.$scale,
                    number_format($count),
                    number_format($count / $inCycle->count() * 100, 1).'%',
                    // A bar in characters. The shape is what this report is for, and a
                    // column of numbers hides it from everybody who is not looking for it.
                    $this->bar($count, (int) $counts->max()),
                ];
            }

            foreach ($inCycle->groupBy(fn (Review $review): string => (string) ($review->employee?->department ?: 'No department')) as $department => $forDepartment) {
                $average = $forDepartment->avg('final_rating');

                $rows[] = [
                    '',
                    '  '.$department,
                    number_format($forDepartment->count()),
                    'avg '.number_format((float) $average, 2),
                    '',
                ];
            }
        }

        return $this->table(
            'RatingsDistribution',
            'Ratings Distribution',
            $this->subtitle($period['from'].' to '.$period['to']),
            ['Cycle', 'Rating', 'People', 'Share', ''],
            'minmax(0, 16rem) minmax(0, 1fr) 7rem 8rem 12rem',
            [2, 3],
            $rows,
            [
                ['label' => 'RATINGS SHARED', 'value' => (float) $rated, 'accent' => true],
                ['label' => 'CYCLES', 'value' => (float) $cycles->count(), 'accent' => false],
            ],
            $rows === []
                ? 'NO RATINGS HAVE BEEN SHARED IN THIS PERIOD'
                : mb_strtoupper($rated.' shared rating(s) across '.$cycles->count().' cycle(s)'),
            null,
            'No ratings have been shared yet. A rating counts here once the manager shares it.',
        );
    }

    /** A proportional bar, at most twelve characters wide. */
    private function bar(int $count, int $most): string
    {
        return $most < 1 || $count < 1 ? '' : str_repeat('█', max(1, (int) round($count / $most * 12)));
    }
}
