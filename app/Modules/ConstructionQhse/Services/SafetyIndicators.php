<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Modules\ConstructionQhse\Support\ExposureHours;
use App\Modules\ConstructionQhse\Support\SafetyRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The QHSE indicators — `docs/construction-management-plan.md` §17.6.
 *
 * **Every lagging indicator here can return "cannot be computed", and that is the whole design.** §17.6 is emphatic
 * about the failure it prevents: "with no diary the denominator is zero and the frequency rate renders as `0.00`, which
 * reads as a perfect safety record and actually means nobody filled anything in. The page must say **insufficient
 * exposure data** and refuse to render a rate."
 *
 * So no method here returns a bare float. Each returns a `SafetyRate`, which is either a figure *with the base it was
 * computed on* or a refusal *with the reason*. A caller cannot accidentally print a zero, because there is no zero to
 * print.
 *
 * **The base travels with the figure**, and §17.6 says why: "a frequency rate without its base is a number that gets
 * compared against a competitor's figure computed on a different one, and 1,000,000 against 200,000 is a factor of five
 * with both called *the standard*."
 *
 * **The denominator has exactly one source per job.** §17.6's mirror-image failure is "double counting the same people
 * from the diary *and* from Timesheets, which halves every rate" — a halved rate being worse than a missing one, because
 * it looks like a number somebody can act on. `construction_jobs.exposure_hours_source` names the one, the report prints
 * which it used, and a job that has not chosen is a *third* state the report names rather than guessing at.
 *
 * **Both sources are read with the query builder rather than through their models.** `construction_qhse` requires only
 * `construction`, and §17.6's exposure hours live in `construction_field`'s daily log while the alternative lives in
 * `timesheets` — naming either class would put a module in this one's import graph that a customer may not have bought.
 * `KNOWN_COUPLINGS` says this module reaches only Invoicing, and this is where that claim is kept true.
 */
class SafetyIndicators
{
    /**
     * The hours a job was exposed for, from whichever single source it names.
     *
     * Returns an `ExposureHours`, which carries the figure, the source it came from, and — when there is no figure — the
     * reason. Three distinguishable states, because §17.6 needs them distinguished: no source chosen, a source chosen
     * whose module is not licensed, and a source with nothing in it.
     */
    public function exposureHours(Job $job, string $from, string $to): ExposureHours
    {
        return match ($job->exposure_hours_source) {
            'daily_log' => $this->fromDailyLog($job, $from, $to),
            'timesheets' => $this->fromTimesheets($job, $from, $to),
            default => ExposureHours::unavailable(
                'No exposure source is set for this job. Choose the site diary or Timesheets on the job — counting '
                .'both would halve every rate below, which is worse than having none.'
            ),
        };
    }

    /**
     * §16.1's manpower hours, read out of the table.
     *
     * **Approved diaries only**, which is `DailyLogService::exposureHours()`'s own rule and repeated here because the
     * query is: a rate computed from drafts would move every time somebody edited one. Overtime counts — an hour of
     * exposure is an hour whatever it was paid at.
     *
     * This deliberately restates that method's query rather than calling it, and the duplication is the price of the
     * boundary: `construction_qhse` requires only `construction`, so naming `DailyLogService` would put the field module
     * in this one's import graph and `ModuleBoundaryTest` would be right to fail it. The arithmetic is the same fold —
     * `hours + overtime_hours` per manpower row, which is what `DailyLog::totalManHours()` sums — and the *rule* is
     * stated in both places on purpose, because a second reader of the same rows that quietly counted drafts would
     * produce two different safety rates from one site.
     */
    private function fromDailyLog(Job $job, string $from, string $to): ExposureHours
    {
        if (! modules()->enabled('construction_field')) {
            return ExposureHours::unavailable(
                'This job takes its exposure hours from the site diary, and site operations is not licensed. Nothing '
                .'here can be computed until either the module is licensed or the job names another source.'
            );
        }

        $logs = DB::table('construction_daily_logs')
            ->whereIn('job_id', Job::query()->inSubtree($job)->select('id'))
            ->whereNotNull('approved_at')
            ->whereDate('log_date', '>=', Carbon::parse($from)->toDateString())
            ->whereDate('log_date', '<=', Carbon::parse($to)->toDateString())
            ->pluck('id');

        if ($logs->isEmpty()) {
            return ExposureHours::unavailable(
                'No approved site diary covers this period. An unapproved diary is not evidence, and a rate computed '
                .'from drafts would move every time somebody edited one.'
            );
        }

        $hours = (float) DB::table('construction_daily_log_manpower')
            ->whereIn('daily_log_id', $logs)
            ->sum(DB::raw('hours + overtime_hours'));

        return $hours <= 0.0
            ? ExposureHours::unavailable(
                'The approved diaries for this period record no man-hours. Somebody signed off days with no manpower '
                .'on them, so there is no denominator — which is not the same as nobody having been on site.'
            )
            : ExposureHours::of($hours, 'the site diary\'s approved manpower returns');
    }

    /**
     * Timesheets' booked hours, read out of the table.
     *
     * The alternative source for a company that books all its time and keeps no diary. §7 already warns that Timesheets
     * "bills and never costs" — but *hours* are hours, and exposure is the one question where the charge-out ladder that
     * makes those entries wrong for costing does not matter at all.
     */
    private function fromTimesheets(Job $job, string $from, string $to): ExposureHours
    {
        if (! modules()->enabled('timesheets')) {
            return ExposureHours::unavailable(
                'This job takes its exposure hours from Timesheets, and that module is not licensed. Nothing here can '
                .'be computed until either the module is licensed or the job names another source.'
            );
        }

        // The bridge from a timesheet entry to a job is the unconstrained `construction_jobs.project_id`, which §7's
        // labour import already uses — an integer, never a `Project`.
        $projects = Job::query()->inSubtree($job)->pluck('project_id')->filter();

        if ($projects->isEmpty()) {
            return ExposureHours::unavailable(
                'This job takes its exposure hours from Timesheets and names no project, so there is nothing to read '
                .'them against.'
            );
        }

        // Minutes, because that is what the table stores — §7's own comment says why: "a rate multiplied by a rounded
        // decimal of hours accumulates error across a month of entries". Summed as minutes and divided once.
        $minutes = (int) DB::table('timesheet_entries')
            ->whereIn('project_id', $projects)
            ->whereDate('date', '>=', Carbon::parse($from)->toDateString())
            ->whereDate('date', '<=', Carbon::parse($to)->toDateString())
            ->sum('minutes');

        // Billable and non-billable both count. An hour of exposure is an hour whoever paid for it, and filtering on
        // `is_billable` would silently drop every hour of remedial work — which is exactly the work people get hurt on.
        $hours = round($minutes / 60, 2);

        return $hours <= 0.0
            ? ExposureHours::unavailable('No timesheet hours are booked against this job for the period.')
            : ExposureHours::of($hours, 'timesheet hours booked to the job');
    }

    /**
     * **Lost-time injury frequency rate.**
     *
     * Lost-time injuries per `base` hours worked. The base is printed with the figure, always — §17.6: "1,000,000
     * against 200,000 is a factor of five with both called *the standard*."
     */
    public function lostTimeInjuryFrequencyRate(Job $job, string $from, string $to, ?int $base = null): SafetyRate
    {
        return $this->rate(
            $job, $from, $to, $base,
            'Lost-time injury frequency rate',
            Incident::LOST_TIME_KINDS,
        );
    }

    /**
     * **Total recordable incident rate** — anything beyond first aid.
     *
     * First aid is deliberately outside the recordable set (see `Incident::RECORDABLE`): including it is the commonest
     * way a rate becomes incomparable with anybody else's, which is the same complaint §17.6 makes about the base.
     */
    public function totalRecordableIncidentRate(Job $job, string $from, string $to, ?int $base = null): SafetyRate
    {
        return $this->rate(
            $job, $from, $to, $base,
            'Total recordable incident rate',
            Incident::RECORDABLE,
        );
    }

    /**
     * **Accident frequency rate** — every injury, first aid included.
     *
     * Kept beside the recordable rate rather than instead of it, because the two answer different questions: one is how
     * often somebody is hurt badly enough to be counted, and this is how often somebody is hurt at all.
     */
    public function accidentFrequencyRate(Job $job, string $from, string $to, ?int $base = null): SafetyRate
    {
        return $this->rate(
            $job, $from, $to, $base,
            'Accident frequency rate',
            Incident::INJURY_KINDS,
        );
    }

    /**
     * **Severity rate** — days lost per `base` hours worked.
     *
     * Days rather than a count, because ten cases of one day each and one case of ten days are the same severity figure
     * and very different frequency figures. The pair is what says which a site has.
     */
    public function severityRate(Job $job, string $from, string $to, ?int $base = null): SafetyRate
    {
        $exposure = $this->exposureHours($job, $from, $to);
        $base ??= (int) config('construction.qhse.rate_base', 1_000_000);

        if (! $exposure->isAvailable()) {
            return SafetyRate::unavailable('Severity rate', $exposure->reason, $base);
        }

        $daysLost = (int) Incident::query()
            ->forJobTree($job)
            ->occurredBetween($from, $to)
            ->sum('days_lost');

        return SafetyRate::of(
            'Severity rate',
            round($daysLost / $exposure->hours * $base, 2),
            $base,
            $exposure,
            $daysLost,
            'days lost',
        );
    }

    /**
     * The near-miss ratio, which crosses from lagging into leading.
     *
     * Delegated to `IncidentService` rather than recomputed, because it needs no exposure denominator at all — it is a
     * ratio between two counts, and duplicating it here would be a second answer to a question already answered.
     *
     * @return array{near_misses: int, lost_time: int, ratio: float|null}
     */
    public function nearMissRatio(Job $job, string $from, string $to): array
    {
        return app(IncidentService::class)->nearMissRatio($job, $from, $to);
    }

    /**
     * **The leading indicators**, none of which needs an exposure denominator.
     *
     * §17.6's list: near-miss reports, toolbox talks delivered and attended, inspections completed against planned,
     * permits closed on time, overdue actions, induction coverage, and the proportion of hold points released at the
     * first attempt.
     *
     * Every one of them is null-when-unknowable rather than zero, for the reason the whole section exists: a leading
     * indicator that reads perfectly on a site with no data is worse than a blank.
     *
     * @return array<string, mixed>
     */
    public function leadingIndicators(Job $job, string $from, string $to): array
    {
        $talks = app(SitePersonnelService::class)->talkCoverage($job, $from, $to);
        $coverage = app(SitePersonnelService::class)->inductionCoverage($job);

        return [
            'near_miss_reports' => Incident::query()
                ->forJobTree($job)
                ->occurredBetween($from, $to)
                ->ofKinds(Incident::NO_INJURY_KINDS)
                ->count(),

            'toolbox_talks' => $talks['talks'],
            'toolbox_attendances' => $talks['attendances'],
            'toolbox_average_attendance' => $talks['average'],
            'toolbox_unrecorded' => $talks['unrecorded'],

            'inspections_completed' => Inspection::query()
                ->forJobTree($job)
                ->whereNotNull('inspected_on')
                ->whereDate('inspected_on', '>=', Carbon::parse($from)->toDateString())
                ->whereDate('inspected_on', '<=', Carbon::parse($to)->toDateString())
                ->count(),

            /*
             * Planned means the hold and witness points on the plans in force — the attendance the quality plan has
             * committed to. Null where no plan is in force, because "0 of 0" reads as complete.
             */
            'inspections_planned' => $this->plannedInspectionPoints($job),

            'permits_closed_on_time_percent' => app(PermitService::class)->closedOnTimeRate($job, $from, $to),

            'overdue_actions' => QhseAction::query()->forJobTree($job)->overdue()->count(),

            'induction_coverage_percent' => $coverage['percent'],
            'induction_on_site' => $coverage['on_site'],

            'hold_points_first_time_percent' => app(InspectionService::class)->firstTimeHoldPointRate($job),
        ];
    }

    /**
     * Hold and witness points across the job's plans in force.
     *
     * Null rather than zero where no plan is in force: "0 inspections of 0 planned" reads as a complete quality plan, and
     * the honest statement is that there is not one.
     */
    private function plannedInspectionPoints(Job $job): ?int
    {
        $count = ItpActivity::query()
            ->active()
            ->whereIn('point_type', [ItpActivity::POINT_HOLD, ItpActivity::POINT_WITNESS])
            ->whereIn('itp_id', Itp::query()->forJobTree($job)->inForce()->select('id'))
            ->count();

        return $count === 0 ? null : $count;
    }

    /**
     * The shared shape of every frequency rate: a count of incident kinds over exposure hours, times a base.
     *
     * @param  array<int, string>  $kinds
     */
    private function rate(Job $job, string $from, string $to, ?int $base, string $label, array $kinds): SafetyRate
    {
        $base ??= (int) config('construction.qhse.rate_base', 1_000_000);
        $exposure = $this->exposureHours($job, $from, $to);

        if (! $exposure->isAvailable()) {
            return SafetyRate::unavailable($label, $exposure->reason, $base);
        }

        $count = Incident::query()
            ->forJobTree($job)
            ->occurredBetween($from, $to)
            ->ofKinds($kinds)
            ->count();

        return SafetyRate::of(
            $label,
            round($count / $exposure->hours * $base, 2),
            $base,
            $exposure,
            $count,
            'incidents',
        );
    }
}
