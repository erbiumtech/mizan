<?php

namespace App\Modules\Attendance\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday of a pattern.
 *
 * `expected_hours` is the only place this system records how long a working day is,
 * which makes it load-bearing well outside attendance: phase 3a derives the hourly
 * rate that pays overtime by dividing the basic wage by it.
 */
class WorkPatternDay extends Model
{
    protected $fillable = ['work_pattern_id', 'weekday', 'is_working', 'expected_hours', 'start_time', 'end_time'];

    protected $casts = [
        'weekday' => 'integer',
        'is_working' => 'boolean',
        'expected_hours' => 'decimal:2',
    ];

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class, 'work_pattern_id');
    }

    /** Monday … Sunday, for a form and a table that both need to say it. */
    public function weekdayName(): string
    {
        return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][$this->weekday] ?? 'Unknown';
    }
}
