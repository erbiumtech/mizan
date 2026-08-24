<?php

namespace App\Modules\Projects\Support;

use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentCheck;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Environment health and incidents over time — `docs/reports-expansion-plan.md` Phase 3.13.
 *
 * "Checks failed and incidents per project over a period; the existing widgets are point-in-time and this is
 * the history."
 *
 * **The history is thirty days long, and saying so is the most important thing this report does.**
 * `ProjectEnvironmentCheck` is `Prunable` — `projects.health.retention_days`, thirty by default — so the
 * checks behind an uptime figure are *deleted* past that horizon. A report that took the plan's "over a
 * period" at face value and offered a financial-year uptime column would compute it from whatever survived
 * pruning and present the last month as though it were the last eight. So the check window is clamped to the
 * retention horizon, the subtitle states both spans, and the note says the horizon out loud.
 *
 * **Incidents are not pruned, so they really are history.** The two halves of this report therefore cover
 * different spans on purpose: incidents across the report's whole period, checks across whatever is still
 * retained. That is not tidy, and the alternative — quietly shortening the incident history to match the
 * checks — would throw away the only long record there is.
 *
 * **Only *confirmed* incidents are counted as outages.** `ProjectEnvironmentIncident` doubles as the
 * flap-suppression state: an incident row opens on the first failure and is confirmed only once the failure
 * threshold is crossed. Counting the unconfirmed ones would turn every transient blip into an outage, which
 * is precisely what `confirmed_at` exists to prevent — and `EnvironmentHealthOverview` already takes the same
 * view, counting `open()->confirmed()`. The blips are reported separately as what they are.
 *
 * **Uptime is a dash where nothing was checked, never nought.** `ProjectEnvironment::uptimePercent()` says it
 * plainly — "never render 0% for not checked yet" — and an environment nobody has ever checked is the
 * opposite of one that is down: nothing is known about it. The report keeps that distinction and makes the
 * silence its own finding.
 *
 * **An incident that happened while alerts were off or muted is the sharpest finding here.** The outage
 * occurred, the record exists, and nobody was told. Neither existing widget can show it, because both are
 * point-in-time and the mute has usually expired by the time anybody looks.
 */
class EnvironmentReports
{
    use ReportShapes;

    public function environmentHealth(string $asOf): array
    {
        $on = Carbon::parse($asOf);
        $period = ReportPeriod::toDate($asOf);

        $retentionDays = (int) config('projects.health.retention_days', 30);

        // The later of the two: there is no point reading checks from before the period, and none from before
        // the pruning horizon because they are gone.
        $checksFrom = Carbon::parse($period['from'])->max($on->copy()->subDays($retentionDays));

        $environments = ProjectEnvironment::query()->with('project')->get();

        if ($environments->isEmpty()) {
            return $this->emptyHealth($period, $retentionDays);
        }

        $checks = $this->checkCounts($environments, $checksFrom, $on);
        $incidents = $this->incidentsByEnvironment($environments, $period);

        $rows = [];
        $totals = ['checks' => 0, 'failed' => 0, 'incidents' => 0, 'downtime' => 0];
        $open = 0;
        $neverChecked = 0;
        $unalerted = 0;
        $blips = 0;

        foreach ($environments->sortBy([
            fn (ProjectEnvironment $a, ProjectEnvironment $b): int => ($a->project?->name ?? '') <=> ($b->project?->name ?? ''),
            fn (ProjectEnvironment $a, ProjectEnvironment $b): int => $a->kind <=> $b->kind,
        ]) as $environment) {
            $count = $checks[$environment->getKey()] ?? ['total' => 0, 'failed' => 0];
            $all = $incidents->get($environment->getKey(), collect());

            $confirmed = $all->filter(fn (ProjectEnvironmentIncident $i): bool => $i->isConfirmed());
            $unconfirmed = $all->count() - $confirmed->count();
            $openHere = $confirmed->filter(fn (ProjectEnvironmentIncident $i): bool => $i->isOpen())->count();
            $downtime = $this->downtimeMinutes($confirmed, $on);
            $silent = $this->incidentsNobodyHeardAbout($environment, $confirmed);

            $rows[] = [
                (string) ($environment->project?->name ?? 'Project #'.$environment->project_id),
                (string) $environment->label(),
                $this->count($count['total']),
                $this->count($count['failed']),
                $this->uptime($count['total'], $count['failed']),
                $this->count($confirmed->count()),
                $downtime === 0 ? '—' : $this->duration($downtime),
                $this->standing($environment, $openHere, $silent, $count['total']),
            ];

            $totals['checks'] += $count['total'];
            $totals['failed'] += $count['failed'];
            $totals['incidents'] += $confirmed->count();
            $totals['downtime'] += $downtime;
            $open += $openHere;
            $blips += $unconfirmed;
            $unalerted += $silent;

            if ($environment->isMonitorable() && $count['total'] === 0 && $environment->health_checked_at === null) {
                $neverChecked++;
            }
        }

        return $this->table(
            'EnvironmentHealth',
            'Environment Health & Incidents',
            $this->subtitle(
                'incidents between '.$period['from'].' and '.$period['to']
                .'; checks from '.$checksFrom->toDateString().', the '.$retentionDays.'-day retention horizon'
            ),
            ['Project', 'Environment', 'Checks', 'Failed', 'Uptime', 'Incidents', 'Downtime', 'Standing'],
            'minmax(0, 12rem) 11rem 9rem 8rem 9rem 10rem 11rem minmax(12rem, 18rem)',
            [2, 3, 4, 5],
            $rows,
            [
                ['label' => 'INCIDENTS', 'value' => (float) $totals['incidents'], 'accent' => true],
                // The one neither existing widget can show: an outage that happened while nobody was being
                // told. Both widgets are point-in-time, and a mute has usually expired by the time anybody
                // comes looking.
                ['label' => 'NOBODY WAS TOLD', 'value' => (float) $unalerted, 'accent' => false],
            ],
            $this->healthNote($environments->count(), $totals, $open, $neverChecked, $unalerted, $blips, $retentionDays),
            $rows === [] ? null : [
                'Total — '.count($rows).' environments',
                '',
                number_format($totals['checks']),
                number_format($totals['failed']),
                $this->uptime($totals['checks'], $totals['failed']),
                number_format($totals['incidents']),
                $totals['downtime'] === 0 ? '—' : $this->duration($totals['downtime']),
                $open > 0 ? number_format($open).' still open' : 'none open',
            ],
            'No environment is recorded on any project.',
            // Eight columns and a standing carrying sentences. Wider than the pane, so it scrolls rather than
            // being silently clipped — Phase 0.2.
            wide: true,
        );
    }

    /**
     * Where the environment stands, with the findings as suffixes.
     *
     * *Not monitored* comes first and stops there: an environment nobody asked to be watched has no uptime to
     * report and no incident to answer for, and dressing that up with an alert warning would be noise.
     */
    private function standing(ProjectEnvironment $environment, int $open, int $silent, int $checks): string
    {
        if (! $environment->isMonitorable()) {
            return 'Not monitored';
        }

        $label = match (true) {
            $open > 0 => 'Down',
            // Distinguished from *up* deliberately: `uptimePercent()` insists that no history is not nought
            // per cent, and the same holds for the standing. Nothing is known about this environment.
            $checks === 0 && $environment->health_checked_at === null => 'Never checked',
            $environment->health_status === ProjectEnvironment::HEALTH_DOWN => 'Down',
            $environment->health_status === ProjectEnvironment::HEALTH_UP => 'Up',
            default => 'Unknown',
        };

        return implode(' · ', array_filter([
            $label,
            $silent > 0 ? $silent.' unalerted' : null,
            // Stated even where nothing has gone wrong yet, because it is the reason nothing *will* be
            // reported when it does.
            $environment->isMuted() ? 'muted' : null,
            ! $environment->alerts_enabled ? 'alerts off' : null,
        ]));
    }

    /**
     * Incidents that ran while nobody was being alerted.
     *
     * Judged on the environment's alert settings rather than on the incident, because nothing records whether
     * an alert actually went out — `alerts_enabled` and `muted_until` are the state that decides it. Which
     * makes this a *present-tense* reading of a past event, and the limitation is stated in the help rather
     * than smoothed over: an environment muted today will show its earlier incidents as unalerted.
     *
     * @param  Collection<int, ProjectEnvironmentIncident>  $confirmed
     */
    private function incidentsNobodyHeardAbout(ProjectEnvironment $environment, Collection $confirmed): int
    {
        return $environment->alertsActive() ? 0 : $confirmed->count();
    }

    /**
     * Total minutes down across these incidents.
     *
     * An unresolved incident is measured to the date being read rather than to `now()`, so a report run for
     * last quarter does not credit an outage with the months since. That is the same reason every other
     * as-at report in this phase takes its date rather than reading the clock.
     *
     * @param  Collection<int, ProjectEnvironmentIncident>  $confirmed
     */
    private function downtimeMinutes(Collection $confirmed, Carbon $on): int
    {
        return (int) $confirmed->sum(function (ProjectEnvironmentIncident $incident) use ($on): int {
            $until = $incident->resolved_at ?? $on->copy()->endOfDay();

            return max(0, (int) $incident->started_at->diffInMinutes($until));
        });
    }

    /**
     * Check totals and failures per environment, over the retained window.
     *
     * One grouped query rather than `uptimePercent()` per environment — that method is a good rule and the
     * wrong tool here, because it counts backwards from `now()` and this report has to answer as at a date.
     * Its *rule* is honoured instead, in `uptime()`.
     *
     * @param  Collection<int, ProjectEnvironment>  $environments
     * @return array<int, array{total: int, failed: int}>
     */
    private function checkCounts(Collection $environments, Carbon $from, Carbon $on): array
    {
        return ProjectEnvironmentCheck::query()
            ->whereIn('project_environment_id', $environments->modelKeys())
            ->where('checked_at', '>=', $from->copy()->startOfDay())
            ->where('checked_at', '<=', $on->copy()->endOfDay())
            ->groupBy('project_environment_id')
            ->selectRaw('project_environment_id, COUNT(*) as total, SUM(CASE WHEN is_up = 1 THEN 0 ELSE 1 END) as failed')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->project_environment_id => ['total' => (int) $row->total, 'failed' => (int) $row->failed],
            ])
            ->all();
    }

    /**
     * Incidents per environment that overlap the period.
     *
     * Overlap, not containment: an outage that began in June and is still open in February is this period's
     * problem, and one that started inside the period and has not ended is the most urgent row on the report.
     *
     * @param  Collection<int, ProjectEnvironment>  $environments
     * @param  array{from: string, to: string}  $period
     * @return Collection<int, Collection<int, ProjectEnvironmentIncident>>
     */
    private function incidentsByEnvironment(Collection $environments, array $period): Collection
    {
        return ProjectEnvironmentIncident::query()
            ->whereIn('project_environment_id', $environments->modelKeys())
            ->whereDate('started_at', '<=', $period['to'])
            ->where(fn ($query) => $query
                ->whereNull('resolved_at')
                ->orWhereDate('resolved_at', '>=', $period['from']))
            ->get()
            ->groupBy('project_environment_id');
    }

    /**
     * Uptime as a percentage, or a dash where nothing was checked.
     *
     * `ProjectEnvironment::uptimePercent()`'s rule, honoured: "never render 0% for not checked yet". An
     * environment nobody checked is the opposite of one that is down — nought per cent would report the
     * worst possible health for the absence of any information at all.
     */
    private function uptime(int $total, int $failed): string
    {
        return $total === 0 ? '—' : number_format(($total - $failed) / $total * 100, 2).'%';
    }

    /** Minutes as something readable, because 4,317 minutes is not a duration anybody pictures. */
    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.'m';
        }

        if ($minutes < 1440) {
            return intdiv($minutes, 60).'h '.($minutes % 60).'m';
        }

        return intdiv($minutes, 1440).'d '.intdiv($minutes % 1440, 60).'h';
    }

    /** A zero count is a dash: four count columns of noughts is unreadable, and the footer has the totals. */
    private function count(int $value): string
    {
        return $value === 0 ? '—' : number_format($value);
    }

    /**
     * What the period held, and the four things worth knowing beyond the counts.
     *
     * The retention horizon is stated whatever else is true, because it qualifies the uptime column on every
     * row and a reader who does not know it will read a month as a year.
     *
     * @param  array<string, int>  $totals
     */
    private function healthNote(
        int $environments,
        array $totals,
        int $open,
        int $neverChecked,
        int $unalerted,
        int $blips,
        int $retentionDays,
    ): string {
        return mb_strtoupper(implode(' · ', array_filter([
            $environments.' environments',
            $totals['incidents'].' confirmed incidents'
                .($totals['downtime'] > 0 ? ' totalling '.$this->duration($totals['downtime']).' down' : ''),
            $open > 0 ? $open.' still open now' : null,
            $unalerted > 0
                ? $unalerted.' happened with alerts off or muted, so nobody was told'
                : null,
            $neverChecked > 0
                ? $neverChecked.' monitored but never checked, so nothing is known about '
                    .($neverChecked === 1 ? 'it' : 'them')
                : null,
            $blips > 0
                ? $blips.' unconfirmed blip'.($blips === 1 ? '' : 's').' suppressed rather than counted'
                : null,
            'uptime covers the last '.$retentionDays.' days only — older checks are pruned',
        ])));
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptyHealth(array $period, int $retentionDays): array
    {
        return $this->table(
            'EnvironmentHealth',
            'Environment Health & Incidents',
            $this->subtitle(
                'incidents between '.$period['from'].' and '.$period['to']
                .'; checks over the last '.$retentionDays.' days'
            ),
            ['Project', 'Environment', 'Checks', 'Failed', 'Uptime', 'Incidents', 'Downtime', 'Standing'],
            'minmax(0, 12rem) 11rem 9rem 8rem 9rem 10rem 11rem minmax(12rem, 18rem)',
            [2, 3, 4, 5],
            [],
            [
                ['label' => 'INCIDENTS', 'value' => 0.0, 'accent' => true],
                ['label' => 'NOBODY WAS TOLD', 'value' => 0.0, 'accent' => false],
            ],
            'NO ENVIRONMENT IS RECORDED ON ANY PROJECT, SO NOTHING IS BEING WATCHED',
            null,
            'No environment is recorded on any project.',
            wide: true,
        );
    }
}
