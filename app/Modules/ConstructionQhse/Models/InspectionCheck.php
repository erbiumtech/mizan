<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a check sheet — `docs/construction-management-plan.md` §17.1.
 *
 * **Expected against actual, with a unit.** "Concrete cube at 28 days" is a number somebody compares with a standard;
 * a paragraph saying it looked fine is not a record, and the difference is what a certification body reads.
 *
 * `passed` is **nullable rather than defaulting to false**, and that is the decision on this table. A check sheet is
 * filled in as the inspection proceeds, and a false default would make every line nobody has reached yet read as a
 * failure — an inspection that failed on lines nobody looked at is worse than no record at all.
 */
class InspectionCheck extends Model
{
    protected $table = 'construction_inspection_checks';

    protected $fillable = [
        'inspection_id', 'sequence', 'description', 'expected_value', 'actual_value', 'unit', 'passed', 'notes',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'passed' => 'boolean',
    ];

    protected $attributes = [
        'sequence' => 0,
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id');
    }

    /** Not yet decided, which is a different state from failed. */
    public function isOutstanding(): bool
    {
        return $this->passed === null;
    }

    public function hasFailed(): bool
    {
        return $this->passed === false;
    }

    /** `40 N/mm² expected, 43 N/mm² measured` — how a check sheet prints. */
    public function describe(): string
    {
        $unit = $this->unit ? ' '.$this->unit : '';

        return trim(($this->expected_value !== null ? "{$this->expected_value}{$unit} expected" : '')
            .($this->actual_value !== null ? ", {$this->actual_value}{$unit} measured" : ''), ', ');
    }
}
