<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One incident — `docs/construction-management-plan.md` §17.3, to ISO 45001.
 *
 * **Near miss is a kind, not a flag**, and §17.3 gives the reason in one sentence: "near-misses reported per lost-time
 * injury is the leading indicator that predicts the next one". A near miss has no injury record — nobody was hurt — so
 * storing it as a checkbox on an injury row means it cannot be counted, and the ratio that predicts the next
 * lost-time injury cannot be computed at all.
 *
 * **`occurred_at` is a datetime because shift timing is half the analysis.** Hour ten of a twelve-hour shift, the first
 * hour after a break, the last night of a run of nights — none of that survives a date column.
 *
 * **The reporting delay is computed and is itself a metric.** A site that takes four days to report a first-aid case is
 * a site where the next one is not reported at all, and that is visible only because `reported_at` is kept separately
 * from `occurred_at` and from `created_at`.
 *
 * **The injured person's name works without an employee record.** §17.3: "a subcontractor's labourer is not in this
 * system, and pretending otherwise loses the record entirely."
 */
class Incident extends Model
{
    use Auditable;

    public const KIND_NEAR_MISS = 'near_miss';

    public const KIND_FIRST_AID = 'first_aid';

    public const KIND_MEDICAL_TREATMENT = 'medical_treatment';

    public const KIND_RESTRICTED_WORK = 'restricted_work';

    public const KIND_LOST_TIME = 'lost_time_injury';

    public const KIND_FATALITY = 'fatality';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_NEAR_MISS => 'Near miss',
        'unsafe_act' => 'Unsafe act',
        'unsafe_condition' => 'Unsafe condition',
        self::KIND_FIRST_AID => 'First aid',
        self::KIND_MEDICAL_TREATMENT => 'Medical treatment',
        self::KIND_RESTRICTED_WORK => 'Restricted work',
        self::KIND_LOST_TIME => 'Lost-time injury',
        self::KIND_FATALITY => 'Fatality',
        'property_damage' => 'Property damage',
        'environmental' => 'Environmental',
        'fire' => 'Fire',
        'security' => 'Security',
        'dangerous_occurrence' => 'Dangerous occurrence',
        'occupational_illness' => 'Occupational illness',
    ];

    /**
     * The kinds that count in a total recordable incident rate.
     *
     * The OSHA-style definition: anything beyond first aid. First aid is deliberately *out* — including it is the
     * commonest way a recordable rate ends up incomparable with anybody else's, and §17.6's whole complaint is about
     * figures compared against a competitor's computed on a different basis.
     */
    public const RECORDABLE = [
        self::KIND_MEDICAL_TREATMENT, self::KIND_RESTRICTED_WORK, self::KIND_LOST_TIME, self::KIND_FATALITY,
        'occupational_illness',
    ];

    /** The kinds that stop somebody working, which is what a frequency rate counts. */
    public const LOST_TIME_KINDS = [self::KIND_LOST_TIME, self::KIND_FATALITY];

    /** Nobody was hurt — and these are the ones worth *more* than the injuries, as leading indicators. */
    public const NO_INJURY_KINDS = [self::KIND_NEAR_MISS, 'unsafe_act', 'unsafe_condition'];

    public const STATUS_REPORTED = 'reported';

    public const STATUS_UNDER_INVESTIGATION = 'under_investigation';

    public const STATUS_CLOSED = 'closed';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_REPORTED => 'Reported',
        self::STATUS_UNDER_INVESTIGATION => 'Under investigation',
        self::STATUS_CLOSED => 'Closed',
    ];

    protected $table = 'construction_incidents';

    protected $fillable = [
        'job_id', 'contract_id', 'incident_number', 'kind', 'occurred_at', 'reported_at', 'reported_by',
        'location_id', 'location_detail', 'activity_being_performed',
        'injured_person_type', 'employee_id', 'injured_person_name', 'injured_person_employer', 'injured_person_age',
        'description', 'immediate_action',
        'is_lost_time', 'days_lost', 'restricted_days', 'treatment', 'body_part', 'injury_type', 'agency',
        'immediate_cause', 'root_cause', 'root_cause_method', 'investigated_by', 'investigation_completed_on',
        'reportable_to_authority', 'authority_name', 'authority_reference', 'reported_to_authority_on',
        'status', 'closed_on', 'notes',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'investigation_completed_on' => 'date',
        'reported_to_authority_on' => 'date',
        'closed_on' => 'date',
        'is_lost_time' => 'boolean',
        'reportable_to_authority' => 'boolean',
        'days_lost' => 'integer',
        'restricted_days' => 'integer',
    ];

    protected $attributes = [
        'kind' => self::KIND_NEAR_MISS,
        'status' => self::STATUS_REPORTED,
        'injured_person_type' => 'nobody',
        'is_lost_time' => false,
        'reportable_to_authority' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            $incident->reported_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function witnesses(): HasMany
    {
        return $this->hasMany(IncidentWitness::class, 'incident_id')->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(IncidentPhoto::class, 'incident_id')->orderBy('id');
    }

    /** Actions raised from it — §17.4's one table. */
    public function actions(): MorphMany
    {
        return $this->morphMany(QhseAction::class, 'subject');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    /**
     * Incidents in a window, by when they *happened*.
     *
     * Not by when they were reported: a rate for August has to contain what happened in August, whatever month somebody
     * typed it in.
     */
    public function scopeOccurredBetween(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->where('occurred_at', '>=', Carbon::parse($from)->startOfDay())
            ->where('occurred_at', '<=', Carbon::parse($to)->endOfDay());
    }

    public function scopeOfKinds(Builder $query, array $kinds): Builder
    {
        return $query->whereIn('kind', $kinds);
    }

    public function scopeReportable(Builder $query): Builder
    {
        return $query->where('reportable_to_authority', true);
    }

    public function isNearMiss(): bool
    {
        return $this->kind === self::KIND_NEAR_MISS;
    }

    /** Nobody was hurt — the leading indicators, and the ones worth encouraging. */
    public function hurtNobody(): bool
    {
        return in_array($this->kind, self::NO_INJURY_KINDS, true);
    }

    /** Recordable on an OSHA-style basis: beyond first aid. */
    public function isRecordable(): bool
    {
        return in_array($this->kind, self::RECORDABLE, true);
    }

    public function isLostTime(): bool
    {
        return in_array($this->kind, self::LOST_TIME_KINDS, true) || $this->is_lost_time;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * **The reporting delay, in hours** — §17.3's own safety metric.
     *
     * Null where it was never reported separately, which is honest: an incident with no reported-at stamp has no delay,
     * it has a missing fact.
     */
    public function reportingDelayHours(): ?int
    {
        if ($this->reported_at === null) {
            return null;
        }

        return max(0, (int) $this->occurred_at->diffInHours($this->reported_at, absolute: false));
    }

    /**
     * Reported later than a site should take.
     *
     * The threshold is configuration rather than a constant, because "late" is a policy: a company whose procedure says
     * two hours is not measuring the same thing as one that says a shift.
     */
    public function wasReportedLate(): bool
    {
        $delay = $this->reportingDelayHours();

        return $delay !== null && $delay > (int) config('construction.qhse.report_within_hours', 24);
    }

    /**
     * Who it happened to, whatever records exist.
     *
     * The free-text name first, because it is the one that always works — most people on most sites are somebody else's
     * employees.
     */
    public function personName(): string
    {
        if (filled($this->injured_person_name)) {
            return $this->injured_person_name
                .($this->injured_person_employer ? " ({$this->injured_person_employer})" : '');
        }

        return $this->injured_person_type === 'nobody' ? 'Nobody hurt' : 'Not named';
    }

    /** Which hour of the day it happened in — the analysis §17.3 keeps a datetime for. */
    public function hourOfDay(): int
    {
        return (int) $this->occurred_at->format('G');
    }

    /**
     * A reportable incident nobody has reported to the authority.
     *
     * The exposure this register carries, and it is the sharpest one in the module: a statutory duty with a clock on it,
     * and nothing else in the application watching.
     */
    public function reportableAndUnreported(): bool
    {
        return $this->reportable_to_authority && $this->reported_to_authority_on === null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function displayName(): string
    {
        return "{$this->incident_number} — ".$this->kindLabel();
    }
}
