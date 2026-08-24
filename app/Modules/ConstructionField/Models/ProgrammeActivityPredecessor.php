<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One logical link between two activities — `docs/construction-management-plan.md` §13.
 *
 * **Stored so an imported network round-trips, and never used to calculate a date.** §13 is explicit: predecessors exist
 * "so an imported network round-trips and a look-ahead can show what is blocking, **and no date is ever calculated from
 * them**". There is no method on this class or anywhere near it that turns a relationship and a lag into a start date,
 * and that absence is the design rather than an omission — the forward pass belongs to P6, which produced the accepted
 * programme.
 *
 * The relationship type and the lag are kept because P6 and MS Project both export them and a round-trip that dropped
 * them would corrupt the planner's file. A negative lag is a *lead*, which is how overlapping trades are programmed, so
 * the column is signed.
 *
 * What these rows are actually read for: **what is blocking this activity.** A look-ahead that says "cladding cannot
 * start" is worth little; one that says "cladding cannot start, and the two activities in front of it have not started
 * either" is a conversation. That question needs the links and no arithmetic at all.
 */
class ProgrammeActivityPredecessor extends Model
{
    public const FINISH_TO_START = 'fs';

    public const START_TO_START = 'ss';

    public const FINISH_TO_FINISH = 'ff';

    public const START_TO_FINISH = 'sf';

    /**
     * The four relationships P6 exports.
     *
     * @var array<string, string>
     */
    public const RELATIONSHIPS = [
        self::FINISH_TO_START => 'Finish to start',
        self::START_TO_START => 'Start to start',
        self::FINISH_TO_FINISH => 'Finish to finish',
        self::START_TO_FINISH => 'Start to finish',
    ];

    protected $table = 'construction_activity_predecessors';

    protected $fillable = [
        'activity_id', 'predecessor_activity_id', 'relationship', 'lag_days',
    ];

    protected $casts = [
        'lag_days' => 'integer',
    ];

    protected $attributes = [
        'relationship' => self::FINISH_TO_START,
        'lag_days' => 0,
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProgrammeActivity::class, 'activity_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(ProgrammeActivity::class, 'predecessor_activity_id');
    }

    public function relationshipLabel(): string
    {
        return self::RELATIONSHIPS[$this->relationship] ?? $this->relationship;
    }

    /** A negative lag is a lead — overlapping trades, not a data error. */
    public function isLead(): bool
    {
        return $this->lag_days < 0;
    }

    /**
     * `FS +5 days` — how a planner writes it.
     *
     * A label rather than a calculation, which is the only thing this application does with a lag.
     */
    public function describe(): string
    {
        $type = strtoupper($this->relationship);

        if ($this->lag_days === 0) {
            return $type;
        }

        return $type.' '.($this->lag_days > 0 ? '+' : '').$this->lag_days.' days';
    }
}
