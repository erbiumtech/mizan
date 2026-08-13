<?php

namespace App\Modules\Timesheets\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's time on one project on one day.
 *
 * Not an attendance record, and the distinction is load-bearing: attendance says
 * somebody was at work, this says what they worked on. An eight-hour day with six hours
 * booked is normal. The two are compared in a report and never enforced against each
 * other.
 */
class TimesheetEntry extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['date'];

    protected $fillable = [
        'employee_id', 'project_id', 'date', 'minutes', 'is_billable',
        'task', 'description', 'approved_by', 'approved_at', 'locked_at',
    ];

    protected $casts = [
        'date' => 'date',
        'minutes' => 'integer',
        'is_billable' => 'boolean',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    protected $attributes = [
        'is_billable' => true,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('is_billable', true);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }

    /** Not yet billed. What a billing run may still pick up. */
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->whereNull('locked_at');
    }

    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('date', $year)->whereMonth('date', $month);
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** Billed, and therefore frozen: a client has paid for this hour. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function hours(): float
    {
        return round($this->minutes / 60, 2);
    }
}
