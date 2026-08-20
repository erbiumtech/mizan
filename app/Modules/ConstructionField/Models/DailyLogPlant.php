<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What plant was on site and what it did — §16.1.
 *
 * **Working, idle and breakdown are three columns because they are three different arguments.** §16.1: "idle against
 * working is what a standing-time claim is made of and one combined hours column loses it entirely." Breakdown is kept
 * apart again because it is usually the contractor's own risk where idle is not, and a claim that mixes the two invites
 * the whole thing to be refused.
 *
 * `plant_item_id` is unconstrained with a label beside it, for the same licensing reason as the manpower row's trade:
 * `construction_plant_items` belongs to `construction_costing`.
 */
class DailyLogPlant extends Model
{
    use Auditable;

    protected $table = 'construction_daily_log_plant';

    protected $fillable = [
        'daily_log_id', 'plant_item_id', 'plant_label',
        'working_hours', 'idle_hours', 'breakdown_hours', 'idle_reason', 'notes',
    ];

    protected $attributes = [
        'working_hours' => 0,
        'idle_hours' => 0,
        'breakdown_hours' => 0,
    ];

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class, 'daily_log_id');
    }

    public function totalHours(): float
    {
        return round(
            (float) $this->working_hours + (float) $this->idle_hours + (float) $this->breakdown_hours,
            2,
        );
    }

    /** Hours the machine was on site and not working — the standing-time figure. */
    public function standingHours(): float
    {
        return round((float) $this->idle_hours + (float) $this->breakdown_hours, 2);
    }

    /**
     * Idle time with no reason against it.
     *
     * Worth asking about rather than tolerating: standing plant is very often somebody else's cost, and idle hours with
     * no reason are the ones nobody can recover next month.
     */
    public function isUnexplainedIdle(): bool
    {
        return (float) $this->idle_hours > 0.0 && trim((string) $this->idle_reason) === '';
    }

    public function displayName(): string
    {
        return $this->plant_label ?: 'Plant';
    }
}
