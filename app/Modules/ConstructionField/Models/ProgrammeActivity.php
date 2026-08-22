<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One activity on the programme — `docs/construction-management-plan.md` §13.
 *
 * **`is_critical` and `total_float_days` are imported, not derived, and nothing in this application computes them.**
 * That is the first thing to know about this model, and §13 requires the docblock to say so. There is no forward pass,
 * no backward pass, no float calculation and no critical-path solver here — the accepted programme was produced in P6
 * or Asta and *that* is the contractual document. A second scheduler that disagreed with it "manufactures a number the
 * quantity surveyor will quote in a claim and the planner will not recognise, and the disagreement stays invisible
 * until an adjudication".
 *
 * Predecessors are stored so an imported network round-trips and a look-ahead can say what is blocking. **No date on
 * this model is ever calculated from them.**
 *
 * **Baseline and planned are two different pairs of dates, and the distinction is the whole of an extension-of-time
 * argument.** Baseline is the *accepted* programme — what entitlement is measured against. Planned is the current one,
 * which moves every month. Collapsing them into one pair would make every re-programme silently retire the entitlement
 * it was caused by.
 *
 * What this model does compute, because none of it needs a network: lateness against the baseline, whether a contract
 * milestone has passed unmet, and how much of that lateness has been granted as an extension of time. That last figure
 * is the liquidated-damages exposure, and it is the reason a programme is worth storing at all.
 */
class ProgrammeActivity extends Model
{
    use Auditable;

    public const SOURCE_MANUAL = 'manual';

    /** @var array<string, string> */
    public const SOURCES = [
        self::SOURCE_MANUAL => 'Entered here',
        'p6_xer' => 'Primavera XER',
        'p6_xml' => 'Primavera P6 XML',
        'msp_xml' => 'MS Project XML',
        'asta' => 'Asta Powerproject',
    ];

    public const TYPE_TASK = 'task';

    public const TYPE_START_MILESTONE = 'start_milestone';

    public const TYPE_FINISH_MILESTONE = 'finish_milestone';

    /** @var array<string, string> */
    public const TYPES = [
        self::TYPE_TASK => 'Task',
        self::TYPE_START_MILESTONE => 'Start milestone',
        self::TYPE_FINISH_MILESTONE => 'Finish milestone',
        'level_of_effort' => 'Level of effort',
        'hammock' => 'Hammock',
        'wbs_summary' => 'WBS summary',
    ];

    /** The two types that mark a moment rather than a span of work. */
    public const MILESTONE_TYPES = [self::TYPE_START_MILESTONE, self::TYPE_FINISH_MILESTONE];

    protected $table = 'construction_activities';

    protected $fillable = [
        'job_id', 'external_id', 'source', 'wbs_node_id', 'parent_id', 'code', 'name', 'activity_type',
        'is_contract_milestone', 'ld_applies',
        'baseline_start', 'baseline_finish', 'planned_start', 'planned_finish', 'actual_start', 'actual_finish',
        'original_duration_days', 'remaining_duration_days', 'percent_complete', 'budgeted_value',
        'total_float_days', 'is_critical', 'responsible_contact_id', 'milestone_payment_item_id',
        'baseline_revision', 'data_date', 'notes',
    ];

    protected $casts = [
        'baseline_start' => 'date',
        'baseline_finish' => 'date',
        'planned_start' => 'date',
        'planned_finish' => 'date',
        'actual_start' => 'date',
        'actual_finish' => 'date',
        'data_date' => 'date',
        'is_contract_milestone' => 'boolean',
        'ld_applies' => 'boolean',
        'is_critical' => 'boolean',
        'original_duration_days' => 'integer',
        'remaining_duration_days' => 'integer',
        'total_float_days' => 'integer',
    ];

    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
        'activity_type' => self::TYPE_TASK,
        'is_contract_milestone' => false,
        'ld_applies' => false,
        'is_critical' => false,
        'percent_complete' => 0,
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Who owns the work.
     *
     * Guarded: Contacts belong to Invoicing and this module requires only `construction`, so a programme is still a
     * programme without them.
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'responsible_contact_id');
    }

    /** What has to happen first — **stored, never solved**. See the class docblock. */
    public function predecessors(): HasMany
    {
        return $this->hasMany(ProgrammeActivityPredecessor::class, 'activity_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(ProgrammeActivityPredecessor::class, 'predecessor_activity_id');
    }

    /** Delay events hung on this activity — §13's third requirement of a programme. */
    public function delayEvents(): HasMany
    {
        return $this->hasMany(DelayEvent::class, 'activity_id');
    }

    public function rfis(): HasMany
    {
        return $this->hasMany(Rfi::class, 'activity_id');
    }

    public function submittals(): HasMany
    {
        return $this->hasMany(Submittal::class, 'activity_id');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeMilestones(Builder $query): Builder
    {
        return $query->where('is_contract_milestone', true);
    }

    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereNull('actual_finish');
    }

    public function scopeNotStarted(Builder $query): Builder
    {
        return $query->whereNull('actual_start');
    }

    /**
     * Activities planned to start within a window — the look-ahead.
     *
     * Read off `planned_start`, which is a stored column, because §13 forbids deriving a start from the network. A
     * look-ahead built by walking predecessors would be a forward pass in all but name.
     */
    public function scopeStartingBetween(Builder $query, string $from, string $to): Builder
    {
        return $query
            // `whereDate`, because `planned_start` is `date`-cast and therefore stored with a time.
            ->whereDate('planned_start', '>=', Carbon::parse($from)->toDateString())
            ->whereDate('planned_start', '<=', Carbon::parse($to)->toDateString());
    }

    public function isMilestone(): bool
    {
        return in_array($this->activity_type, self::MILESTONE_TYPES, true) || $this->is_contract_milestone;
    }

    public function hasStarted(): bool
    {
        return $this->actual_start !== null;
    }

    public function isComplete(): bool
    {
        return $this->actual_finish !== null;
    }

    /**
     * **Days late against the accepted programme**, which is what entitlement is measured against.
     *
     * To the actual finish where there is one, to today where there is not — so the figure stops moving once the work
     * is done. Zero rather than negative when it finished early: a report summing this column wants the loss.
     */
    public function daysLateAgainstBaseline(?string $asAt = null): int
    {
        if ($this->baseline_finish === null) {
            return 0;
        }

        $reference = $this->actual_finish ?? Carbon::parse($asAt ?? now())->startOfDay();

        return max(0, (int) $this->baseline_finish->diffInDays($reference, absolute: false));
    }

    /** The same question of the current programme, which is a different number and a different argument. */
    public function daysLateAgainstPlan(?string $asAt = null): int
    {
        if ($this->planned_finish === null) {
            return 0;
        }

        $reference = $this->actual_finish ?? Carbon::parse($asAt ?? now())->startOfDay();

        return max(0, (int) $this->planned_finish->diffInDays($reference, absolute: false));
    }

    /**
     * Should have started by now and has not.
     *
     * The commonest early signal on a programme, and it needs no network: a stored planned start in the past with no
     * actual against it.
     */
    public function isLateToStart(?string $asAt = null): bool
    {
        return ! $this->hasStarted()
            && $this->planned_start !== null
            && $this->planned_start->lt(Carbon::parse($asAt ?? now())->startOfDay());
    }

    /**
     * **Extension of time already granted against this activity** — the sum of awarded days on its delay events.
     *
     * Reads the loaded relation, because every screen asking this is showing the events.
     */
    public function awardedDays(): int
    {
        return (int) $this->delayEvents
            ->filter(fn (DelayEvent $event): bool => $event->status === DelayEvent::STATUS_DETERMINED)
            ->sum('awarded_days');
    }

    /**
     * **The liquidated-damages exposure: lateness the contract has not excused.**
     *
     * Days late against the accepted programme, less the extension of time actually awarded. This is the figure a
     * programme is worth storing for — it is money leaving with nothing wrong anywhere, and it exists only because
     * baseline and planned dates are kept apart and delay events record what was *determined* rather than what was
     * claimed.
     *
     * Only ever computed for a milestone that the contract prices. §13 keeps `is_contract_milestone` and `ld_applies`
     * separate precisely because a contract names dates it does not charge for.
     */
    public function unexcusedLateDays(?string $asAt = null): int
    {
        if (! $this->ld_applies) {
            return 0;
        }

        return max(0, $this->daysLateAgainstBaseline($asAt) - $this->awardedDays());
    }

    /**
     * Progress measured against elapsed baseline time — a crude schedule check that needs no network.
     *
     * Null where the baseline has no span to measure against, which is honest: §14 builds the real schedule index, and
     * a made-up percentage here would be a second answer to it.
     */
    public function elapsedBaselinePercent(?string $asAt = null): ?float
    {
        if ($this->baseline_start === null || $this->baseline_finish === null) {
            return null;
        }

        $span = (int) $this->baseline_start->diffInDays($this->baseline_finish, absolute: false);

        if ($span <= 0) {
            return null;
        }

        $elapsed = (int) $this->baseline_start->diffInDays(
            Carbon::parse($asAt ?? now())->startOfDay(),
            absolute: false,
        );

        return round(max(0.0, min(100.0, $elapsed / $span * 100)), 2);
    }

    /**
     * Behind where the baseline says it should be.
     *
     * False rather than null when it cannot be told, because a screen filtering on this wants a boolean — and the
     * accessor above is what says "cannot tell".
     */
    public function isBehindBaseline(?string $asAt = null): bool
    {
        $elapsed = $this->elapsedBaselinePercent($asAt);

        return $elapsed !== null && (float) $this->percent_complete < $elapsed;
    }

    /**
     * Where the criticality and float on this row came from.
     *
     * Printed beside them wherever they are shown, because §13's whole argument is that they are somebody else's
     * numbers: a report quoting a critical path has to be able to say which tool produced it.
     */
    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function isImported(): bool
    {
        return $this->source !== self::SOURCE_MANUAL;
    }

    public function displayName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
