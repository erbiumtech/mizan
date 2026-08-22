<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Models\IncidentPhoto;
use App\Modules\ConstructionQhse\Models\IncidentWitness;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Incidents — `docs/construction-management-plan.md` §17.3, to ISO 45001.
 *
 * Five rules live here, and the first is the one the whole register turns on.
 *
 *  - **Reporting a near miss is as easy as reporting an injury**, because it is the same act on the same register.
 *    §17.3: "near-misses reported per lost-time injury is the leading indicator that predicts the next one." A system
 *    that made a near miss harder to record than an injury would suppress exactly the number it most needs.
 *  - **The occurrence time is a datetime and the report time is separate.** Shift timing is half the analysis, and the
 *    reporting delay is itself a metric — so `report()` takes both and neither defaults to the other.
 *  - **Nothing infers `is_lost_time` from a day count.** A lost-time injury with the days not yet known is the ordinary
 *    state for a fortnight, and deriving the flag would classify it as a medical-treatment case for exactly as long as
 *    the reportable clock is running.
 *  - **A reportable incident with no authority date is an exposure, not a state.** A statutory duty with a clock on it
 *    and nothing else in the application watching — which is the sharpest failure in this module.
 *  - **Closing needs an investigation.** An incident closed with no cause recorded is a lesson nobody learned, and the
 *    register would then show a site that has closed everything and understood none of it.
 */
class IncidentService
{
    /**
     * Report one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function report(Job $job, array $attributes): Incident
    {
        if (! array_key_exists($attributes['kind'] ?? Incident::KIND_NEAR_MISS, Incident::KINDS)) {
            throw new InvalidArgumentException(
                'An incident needs a kind. Near miss is one of them and not a lesser one — near misses per lost-time '
                .'injury is the indicator that predicts the next injury.'
            );
        }

        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An incident needs describing. "Near miss" against a location is a row nobody can investigate and '
                .'nobody can learn from.'
            );
        }

        if (blank($attributes['occurred_at'] ?? null)) {
            throw new InvalidArgumentException(
                'An incident needs the time it happened, not just the date. Shift timing is half the analysis — hour ten '
                .'of a twelve-hour shift is a finding and the 14th of August is not.'
            );
        }

        $occurredAt = Carbon::parse($attributes['occurred_at']);

        if ($occurredAt->isFuture()) {
            throw new InvalidArgumentException('An incident cannot have happened in the future.');
        }

        $reportedAt = Carbon::parse($attributes['reported_at'] ?? now());

        if ($reportedAt->lt($occurredAt)) {
            throw new InvalidArgumentException('An incident cannot have been reported before it happened.');
        }

        // Never inferred. See the class docblock.
        $attributes['is_lost_time'] = in_array($attributes['kind'] ?? '', Incident::LOST_TIME_KINDS, true)
            || (bool) ($attributes['is_lost_time'] ?? false);

        return TenantTransaction::run(fn (): Incident => Incident::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'incident_number' => $attributes['incident_number'] ?? $this->nextNumber($job),
            'occurred_at' => $occurredAt,
            'reported_at' => $reportedAt,
        ])));
    }

    /** `INC-1` upward per job, off the trailing digits so an imported register continues rather than colliding. */
    public function nextNumber(Job $job): string
    {
        $used = Incident::query()
            ->where('job_id', $job->getKey())
            ->pluck('incident_number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'INC-'.($used + 1);
    }

    /**
     * Edit it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Incident $incident, array $attributes): Incident
    {
        if ($incident->isClosed()) {
            throw new InvalidArgumentException(
                "{$incident->displayName()} is closed. An incident record is what an investigation concluded — reopen it "
                .'if new facts have come out, so the register says that is what happened.'
            );
        }

        unset($attributes['status'], $attributes['closed_on']);

        // A change of kind may change whether it is lost time, and it is never inferred the other way round.
        if (array_key_exists('kind', $attributes) && in_array($attributes['kind'], Incident::LOST_TIME_KINDS, true)) {
            $attributes['is_lost_time'] = true;
        }

        $incident->update($attributes);

        return $incident->refresh();
    }

    /**
     * Record the investigation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function investigate(Incident $incident, array $attributes): Incident
    {
        if ($incident->isClosed()) {
            throw new InvalidArgumentException("{$incident->displayName()} is closed.");
        }

        $incident->update(array_merge($attributes, [
            'investigated_by' => $attributes['investigated_by'] ?? auth()->id(),
            'status' => Incident::STATUS_UNDER_INVESTIGATION,
        ]));

        return $incident->refresh();
    }

    /**
     * Record that the authority was told.
     *
     * Its own act, because it is a statutory duty with a date attached and the date is what a regulator asks for.
     */
    public function reportToAuthority(Incident $incident, string $authority, ?string $reference = null, ?string $on = null): Incident
    {
        if (! $incident->reportable_to_authority) {
            throw new InvalidArgumentException(
                "{$incident->displayName()} is not marked as reportable. Mark it first — a report to an authority "
                .'against an incident nobody assessed as reportable is a record neither party can rely on.'
            );
        }

        $incident->update([
            'authority_name' => $authority,
            'authority_reference' => $reference,
            'reported_to_authority_on' => Carbon::parse($on ?? now())->toDateString(),
        ]);

        return $incident->refresh();
    }

    /**
     * Close it, which needs a cause recorded.
     *
     * An incident closed with no root cause is a lesson nobody learned — and a register showing a site that has closed
     * everything and understood none of it is worse than one showing the truth.
     */
    public function close(Incident $incident, ?string $on = null): Incident
    {
        if ($incident->isClosed()) {
            throw new InvalidArgumentException("{$incident->displayName()} is already closed.");
        }

        if (blank($incident->root_cause) && blank($incident->immediate_cause)) {
            throw new InvalidArgumentException(
                "{$incident->displayName()} has no cause recorded. An incident closed without one is a lesson nobody "
                .'learned, and a register where everything is closed and nothing is understood is worse than one that '
                .'shows the truth.'
            );
        }

        if ($incident->reportableAndUnreported()) {
            throw new InvalidArgumentException(
                "{$incident->displayName()} is reportable to an authority and nothing records that it was reported. "
                .'Closing it would file a statutory duty as finished.'
            );
        }

        $incident->update([
            'status' => Incident::STATUS_CLOSED,
            'closed_on' => Carbon::parse($on ?? now())->toDateString(),
        ]);

        return $incident->refresh();
    }

    /** Reopen a closed incident, because new facts do come out. */
    public function reopen(Incident $incident, string $reason): Incident
    {
        if (! $incident->isClosed()) {
            throw new InvalidArgumentException("{$incident->displayName()} is not closed.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reopening an incident needs a reason.');
        }

        $incident->update([
            'status' => Incident::STATUS_UNDER_INVESTIGATION,
            'closed_on' => null,
            'notes' => trim(($incident->notes ? $incident->notes."\n\n" : '').'Reopened: '.$reason),
        ]);

        return $incident->refresh();
    }

    /**
     * Add a witness.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addWitness(Incident $incident, array $attributes): IncidentWitness
    {
        if (trim((string) ($attributes['name'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A witness needs a name. Most witnesses on most sites are somebody else\'s employees, so a name typed '
                .'in is the whole of what is available — and it is enough.'
            );
        }

        return $incident->witnesses()->create($attributes);
    }

    /**
     * Add a photograph.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addPhoto(Incident $incident, array $attributes): IncidentPhoto
    {
        if (blank($attributes['file_path'] ?? null)) {
            throw new InvalidArgumentException('A photograph needs a file.');
        }

        if (trim((string) ($attributes['caption'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A photograph needs a caption. An uncaptioned image of a scene nobody can identify is worth nothing to '
                .'an investigation six months later.'
            );
        }

        return $incident->photos()->create($attributes + ['taken_at' => $incident->occurred_at]);
    }

    /**
     * **Reportable and not reported** — the exposure.
     *
     * A statutory duty with a clock on it, and nothing else in the application watching.
     *
     * @return Collection<int, Incident>
     */
    public function reportableAndUnreported(Job $job): Collection
    {
        return Incident::query()
            ->forJobTree($job)
            ->reportable()
            ->whereNull('reported_to_authority_on')
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Incidents reported later than policy allows — §17.3's own metric.
     *
     * Filtered in PHP because the comparison is a datetime difference against a configured number of hours, which is a
     * dialect-specific expression in SQL — the rule Phase 9e recorded.
     *
     * @return Collection<int, Incident>
     */
    public function reportedLate(Job $job): Collection
    {
        return Incident::query()
            ->forJobTree($job)
            ->whereNotNull('reported_at')
            ->get()
            ->filter(fn (Incident $incident): bool => $incident->wasReportedLate())
            ->values();
    }

    /**
     * The average reporting delay, in hours.
     *
     * Null rather than zero where nothing has a report stamp: §17.6's complaint about rates computed from nothing
     * applies here too, and "zero hours" on a site that records nothing is the most flattering wrong answer available.
     */
    public function averageReportingDelayHours(Job $job, ?string $from = null, ?string $to = null): ?float
    {
        $incidents = Incident::query()
            ->forJobTree($job)
            ->whereNotNull('reported_at')
            ->when($from && $to, fn ($q) => $q->occurredBetween($from, $to))
            ->get();

        if ($incidents->isEmpty()) {
            return null;
        }

        return round((float) $incidents->avg(fn (Incident $i): int => (int) $i->reportingDelayHours()), 1);
    }

    /**
     * Counts by kind over a period — the raw material of §17.6's indicators.
     *
     * By when they *happened*, not when they were typed: a rate for August has to contain what happened in August.
     *
     * @return array<string, int>
     */
    public function countsByKind(Job $job, string $from, string $to): array
    {
        return Incident::query()
            ->forJobTree($job)
            ->occurredBetween($from, $to)
            ->get()
            ->groupBy('kind')
            ->map(fn (Collection $incidents): int => $incidents->count())
            ->all();
    }

    /**
     * **The near-miss ratio: how many near misses per lost-time injury.**
     *
     * §17.3 calls this "the leading indicator that predicts the next one", and it is the reason near miss is a kind
     * rather than a checkbox.
     *
     * Null when there is no lost-time injury to divide by — which is the *good* state and must not read as a bad ratio.
     * A large number here is a site where people report things; a small one is a site where they do not.
     *
     * @return array{near_misses: int, lost_time: int, ratio: float|null}
     */
    public function nearMissRatio(Job $job, string $from, string $to): array
    {
        $counts = $this->countsByKind($job, $from, $to);

        $nearMisses = collect(Incident::NO_INJURY_KINDS)->sum(fn (string $kind): int => $counts[$kind] ?? 0);
        $lostTime = collect(Incident::LOST_TIME_KINDS)->sum(fn (string $kind): int => $counts[$kind] ?? 0);

        return [
            'near_misses' => (int) $nearMisses,
            'lost_time' => (int) $lostTime,
            'ratio' => $lostTime === 0 ? null : round($nearMisses / $lostTime, 1),
        ];
    }
}
