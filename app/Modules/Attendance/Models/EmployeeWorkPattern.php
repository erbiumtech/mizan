<?php

namespace App\Modules\Attendance\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Which pattern an employee was on, and from when.
 *
 * Dated rather than a foreign key on `employees` for the same reason
 * `employee_job_history` exists: somebody moving from the factory floor to the office
 * in March must not retrospectively have been on the office pattern in February. A
 * February attendance row read against today's pattern would call a worked Saturday a
 * weekly off, and phase 2a would credit a comp-off for it.
 */
class EmployeeWorkPattern extends Model
{
    use Auditable;
    use StoresPlainDates;

    /** Plain dates so the "in force on" comparison behaves the same on MySQL and SQLite. */
    protected array $plainDates = ['from_date', 'to_date'];

    protected $fillable = ['employee_id', 'work_pattern_id', 'from_date', 'to_date'];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class, 'work_pattern_id');
    }

    /** Assignments in force on a date. Open-ended `to_date` means still current. */
    public function scopeInForceOn(Builder $query, string|Carbon $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return $query->where('from_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('to_date')->orWhere('to_date', '>=', $date));
    }
}
