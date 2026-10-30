<?php

namespace App\Modules\Performance\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\ScorecardProfile;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;

/**
 * Scorecards — docs/saas-launch-plan.md §14.10.
 *
 * **Quality and volume beside the net, never folded into it.** Two people on the same
 * net score can be opposite employees — one who does little, carefully, and one who
 * does a great deal badly — and a single number hides exactly the distinction the
 * report exists to show.
 *
 * The weights come from the designation's profile, which is what makes a salesman's
 * week count differently from a draughtsman's. A designation with no profile counts
 * everything once, which is the ledger unweighted and what a company had before any
 * of this existed.
 */
class Scorecards
{
    use ReportShapes;

    public function scorecard(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $employees = Employee::query()->with('user')->where('is_active', true)->orderBy('employee_id')->get();

        $entries = MeritEntry::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('occurred_on', [$period['from'], $period['to']])
            ->get()
            ->groupBy('employee_id');

        $profiles = ScorecardProfile::query()->active()->get()->keyBy('designation');

        $rows = [];
        $unweighted = 0;

        foreach ($employees as $employee) {
            $mine = $entries->get($employee->getKey(), collect());

            if ($mine->isEmpty()) {
                continue;
            }

            $profile = $profiles->get((string) $employee->designation);
            $unweighted += $profile === null ? 1 : 0;

            $quality = 0.0;
            $volume = 0.0;

            foreach ($mine as $entry) {
                $weighted = (float) $entry->points * ($profile?->weightFor((string) $entry->source_type) ?? ScorecardProfile::DEFAULT_WEIGHT);

                ScorecardProfile::bucketOf((string) $entry->source_type) === 'quality'
                    ? $quality += $weighted
                    : $volume += $weighted;
            }

            $rows[] = [
                'sort' => $quality + $volume,
                'row' => [
                    (string) ($employee->display_label ?? 'Employee #'.$employee->getKey()),
                    (string) ($employee->designation ?: '—'),
                    $profile === null ? 'Unweighted' : 'Weighted',
                    $this->signed($quality),
                    $this->signed($volume),
                    $this->signed($quality + $volume),
                ],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);
        $rows = array_map(fn (array $entry): array => $entry['row'], $rows);

        return $this->table(
            'Scorecards',
            'Scorecards',
            $this->subtitle($period['from'].' to '.$period['to']),
            ['Employee', 'Designation', 'Profile', 'Quality', 'Volume', 'Net'],
            'minmax(0, 1fr) 13rem 9rem 8rem 8rem 8rem',
            [3, 4, 5],
            $rows,
            [
                ['label' => 'SCORED', 'value' => (float) count($rows), 'accent' => true],
                // The number worth acting on: people whose designation has no profile are
                // being judged on a ledger nobody weighted for their job.
                ['label' => 'UNWEIGHTED', 'value' => (float) $unweighted, 'accent' => false],
            ],
            $rows === []
                ? 'NOTHING IS ON THE LEDGER FOR THIS PERIOD'
                : mb_strtoupper(count($rows).' scored'
                    .($unweighted > 0 ? ', '.$unweighted.' on a designation with no profile' : '')),
            null,
            'Nothing is on the merit ledger for this period.',
        );
    }

    /** A score, with its sign — a minus matters more than the digits here. */
    private function signed(float $points): string
    {
        return ($points > 0 ? '+' : '').rtrim(rtrim(number_format($points, 1), '0'), '.');
    }
}
