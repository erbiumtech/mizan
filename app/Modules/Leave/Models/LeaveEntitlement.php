<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What one employee is entitled to, of one type, in one leave year.
 *
 * There is no `balance` column and there will not be one. The balance is
 * `opening + carried_in + accrued + adjustments − taken`, and `taken` is a sum over
 * leave_days — see LeaveBalance. A stored balance drifts against the days actually
 * consumed and nothing reports the drift, which is the same reason account balances
 * are computed from journal lines rather than kept on the account.
 *
 * The leave-year window is stored rather than derived, and that is what makes
 * leave.year_basis safe to change mid-year. See the migration.
 */
class LeaveEntitlement extends Model
{
    use Auditable;
    use StoresPlainDates;

    /** Stored as plain dates so the window comparisons behave the same on MySQL and SQLite. */
    protected array $plainDates = ['leave_year_start', 'leave_year_end'];

    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_year_start', 'leave_year_end',
        'opening_days', 'accrued_days', 'carried_in_days',
    ];

    protected $casts = [
        'leave_year_start' => 'date',
        'leave_year_end' => 'date',
        'opening_days' => 'decimal:1',
        'accrued_days' => 'decimal:1',
        'carried_in_days' => 'decimal:1',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LeaveAdjustment::class, 'leave_entitlement_id');
    }

    /** The entitlement whose stored window covers a date. */
    public function scopeCovering(Builder $query, string|Carbon $date): Builder
    {
        $date = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return $query->whereDate('leave_year_start', '<=', $date)
            ->whereDate('leave_year_end', '>=', $date);
    }

    /**
     * Everything credited to this entitlement, before anything taken.
     *
     * Adjustments are summed rather than read from a column, which is the whole
     * point of them being rows.
     */
    public function creditedDays(): float
    {
        return (float) $this->opening_days
            + (float) $this->carried_in_days
            + (float) $this->accrued_days
            + (float) $this->adjustments()->sum('days');
    }
}
