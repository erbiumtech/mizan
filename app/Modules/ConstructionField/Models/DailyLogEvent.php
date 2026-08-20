<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened on the day — §16.1.
 *
 * **`responsibility` is recorded on the day, and that is the whole value of it.** The diary is the only document written
 * while anybody still remembers, and an event with nothing against it is the one that gets assigned to the contractor
 * by default six months later when the argument starts.
 *
 * **`delay_event_id` being null is the interesting state.** It is a line in the diary that cost time and that nobody has
 * served notice for — the silent loss §13's clock exists to prevent, now answerable from the diary rather than from
 * somebody's memory. `DailyLog::unnotifiedEvents()` is that question.
 */
class DailyLogEvent extends Model
{
    use Auditable;

    public const KINDS = [
        'delay' => 'Delay',
        'instruction' => 'Instruction',
        'visitor' => 'Visitor',
        'stoppage' => 'Stoppage',
        'inspection' => 'Inspection',
        'incident' => 'Incident',
    ];

    public const RESPONSIBILITIES = [
        'employer' => 'Employer',
        'contractor' => 'Contractor',
        'neutral' => 'Neutral',
    ];

    protected $table = 'construction_daily_log_events';

    protected $fillable = [
        'daily_log_id', 'kind', 'started_at', 'ended_at', 'hours_lost',
        'responsibility', 'delay_event_id', 'description', 'raised_with',
    ];

    protected $attributes = [
        'hours_lost' => 0,
        'responsibility' => 'neutral',
    ];

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class, 'daily_log_id');
    }

    /** The §13 delay event this line belongs to, where somebody has raised one. */
    public function delayEvent(): BelongsTo
    {
        return $this->belongsTo(DelayEvent::class, 'delay_event_id');
    }

    /**
     * Events that cost time, are not the contractor's own, and have no delay event behind them.
     *
     * The exposure query, as a scope so a register can filter on it: money at risk with nobody notified.
     */
    public function scopeUnnotified(Builder $query): Builder
    {
        return $query->whereNull('delay_event_id')
            ->where('hours_lost', '>', 0)
            ->whereNot('responsibility', 'contractor');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function isNotified(): bool
    {
        return $this->delay_event_id !== null;
    }
}
