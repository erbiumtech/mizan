<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day on one job — `docs/construction-management-plan.md` §16.1.
 *
 * **Two properties make this evidence rather than a note.**
 *
 * *One diary per day per job*, enforced by a unique index — §16.1 calls the constraint the feature, "because two site
 * diaries for one day is how a dispute starts". And *approval locks the row*: "an editable site diary is not evidence".
 * A diary anybody can revise afterwards proves nothing about what happened.
 *
 * Reopening exists, with an author and a reason, because the alternative is worse: a signed diary that is wrong would
 * stay wrong for ever, and a company in that position keeps its real diary in a notebook.
 *
 * **The claim lives in two columns, not in the prose.** §16.1: `working_conditions` and `weather_hours_lost` "not the
 * free text, are what a weather-based extension of time is actually made of". A paragraph about rain is unassessable;
 * four hours against a stopped day is a figure §13's delay event can be built on.
 */
class DailyLog extends Model
{
    use Auditable;

    public const CONDITION_WORKABLE = 'workable';

    public const CONDITION_PARTIALLY_DISRUPTED = 'partially_disrupted';

    public const CONDITION_STOPPED = 'stopped';

    /** @var array<string, string> */
    public const CONDITIONS = [
        self::CONDITION_WORKABLE => 'Workable',
        self::CONDITION_PARTIALLY_DISRUPTED => 'Partially disrupted',
        self::CONDITION_STOPPED => 'Stopped',
    ];

    protected $table = 'construction_daily_logs';

    protected $fillable = [
        'job_id', 'log_date', 'weather_am', 'weather_pm', 'temperature_c', 'rainfall_mm', 'wind_kph',
        'working_conditions', 'weather_hours_lost',
        'work_summary', 'delays', 'instructions_received', 'visitors',
        'safety_observations', 'quality_observations', 'environmental_observations',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'reopened_at', 'reopened_by', 'reopen_reason', 'created_by',
    ];

    protected $casts = [
        'log_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    protected $attributes = [
        'working_conditions' => self::CONDITION_WORKABLE,
        'weather_hours_lost' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->created_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function manpower(): HasMany
    {
        return $this->hasMany(DailyLogManpower::class, 'daily_log_id');
    }

    public function plant(): HasMany
    {
        return $this->hasMany(DailyLogPlant::class, 'daily_log_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DailyLogEvent::class, 'daily_log_id');
    }

    /** What arrived, as site recorded it — the docket, never the valuation. See `DailyLogDelivery`. */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DailyLogDelivery::class, 'daily_log_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DailyLogPhoto::class, 'daily_log_id');
    }

    /** Logs for one date — `whereDate`, because `log_date` is `date`-cast and therefore stored with a time. */
    public function scopeOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('log_date', $date);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('approved_at');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    /** Approved, and therefore locked. The one question every other rule here reads. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isEditable(): bool
    {
        return ! $this->isApproved();
    }

    public function wasReopened(): bool
    {
        return $this->reopened_at !== null;
    }

    /** Total labour hours on the day, which is §17's exposure denominator and dayworks' starting point. */
    public function totalManHours(): float
    {
        return round((float) $this->manpower->sum(
            fn (DailyLogManpower $row): float => (float) $row->hours + (float) $row->overtime_hours,
        ), 2);
    }

    public function totalHeadcount(): int
    {
        return (int) $this->manpower->sum('headcount');
    }

    /**
     * Plant hours that were **not** working, which is what a standing-time claim is made of.
     *
     * Idle and breakdown are summed here for a headline figure and kept apart on the rows, because they are different
     * risks: idle is usually somebody else's and breakdown is the contractor's own.
     */
    public function standingPlantHours(): float
    {
        return round((float) $this->plant->sum(
            fn (DailyLogPlant $row): float => (float) $row->idle_hours + (float) $row->breakdown_hours,
        ), 2);
    }

    /** Hours lost across the day's events, beside the weather figure rather than folded into it. */
    public function eventHoursLost(): float
    {
        return round((float) $this->events->sum('hours_lost'), 2);
    }

    /**
     * Diary events that cost time and have **no delay event behind them**.
     *
     * The silent loss §13's clock exists to prevent, asked of the diary rather than of somebody's memory: an event
     * written down on the day, costing hours, that nobody has served notice for.
     *
     * @return \Illuminate\Support\Collection<int, DailyLogEvent>
     */
    public function unnotifiedEvents(): \Illuminate\Support\Collection
    {
        return $this->events
            ->filter(fn (DailyLogEvent $event): bool => $event->delay_event_id === null
                && (float) $event->hours_lost > 0.0
                && $event->responsibility !== 'contractor')
            ->values();
    }

    /**
     * Deliveries this day flagged as standing on site.
     *
     * Corroboration for Phase 8c's stock figure, **not a second source for it**: nothing here decreases when the
     * material is built in, so a total of these overstates what is on site by everything already consumed. See the
     * migration for the whole of that decision.
     *
     * @return \Illuminate\Support\Collection<int, DailyLogDelivery>
     */
    public function materialsOnSiteDeliveries(): \Illuminate\Support\Collection
    {
        return $this->deliveries
            ->filter(fn (DailyLogDelivery $delivery): bool => $delivery->is_materials_on_site)
            ->values();
    }

    /**
     * Photographs of covered work that are not in the register.
     *
     * A photograph of reinforcement before the pour is the only evidence it was there. Sitting in a diary it is
     * findable by whoever remembers the date; in the register it is findable in year four.
     *
     * @return \Illuminate\Support\Collection<int, DailyLogPhoto>
     */
    public function photosNeedingPromotion(): \Illuminate\Support\Collection
    {
        return $this->photos
            ->filter(fn (DailyLogPhoto $photo): bool => $photo->needsPromoting())
            ->values();
    }

    public function displayName(): string
    {
        return ($this->job?->code ?? 'Job').' — '.$this->log_date?->format('D d M Y');
    }
}
