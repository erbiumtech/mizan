<?php

namespace App\Modules\Attendance\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One employee, one day.
 *
 * The only table in the HR schema that grows with usage rather than with headcount,
 * so it is Prunable from the first day rather than after somebody notices.
 *
 * `STATUS_NOT_MARKED` is the important one. A month nobody filled in must not read as
 * everybody absent, because absent costs money — the same distinction the environment
 * health checks make between `down` and `unknown`. Every aggregate in this class
 * treats it as "unknown", never as a zero.
 */
class AttendanceDay extends Model
{
    use Auditable;
    use Prunable;
    use StoresPlainDates;

    /** Plain date: the month aggregates and the unique key both compare it as one. */
    protected array $plainDates = ['date'];

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_WEEKLY_OFF = 'weekly_off';

    public const STATUS_HALF_DAY = 'half_day';

    public const STATUS_WORK_FROM_HOME = 'work_from_home';

    /** Nobody has said. Deliberately not `absent`. */
    public const STATUS_NOT_MARKED = 'not_marked';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_SELF_SERVICE = 'self_service';

    /** Biometric devices are out of scope; the value exists so one can be added. */
    public const SOURCE_DEVICE = 'device';

    /** Statuses on which somebody was actually at work, for pay and for comp-off. */
    public const WORKED_STATUSES = [self::STATUS_PRESENT, self::STATUS_WORK_FROM_HOME, self::STATUS_HALF_DAY];

    /** Statuses the company does not expect work on — what comp-off is earned against. */
    public const NON_WORKING_STATUSES = [self::STATUS_WEEKLY_OFF, self::STATUS_HOLIDAY];

    protected $fillable = [
        'employee_id', 'date', 'status', 'check_in_at', 'check_out_at',
        'worked_minutes', 'overtime_minutes', 'late_minutes', 'source', 'note',
        'leave_request_id',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'worked_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'late_minutes' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_NOT_MARKED,
        'worked_minutes' => 0,
        'overtime_minutes' => 0,
        'late_minutes' => 0,
        'source' => self::SOURCE_MANUAL,
    ];

    /**
     * Rows older than the retention setting.
     *
     * Read through setting() rather than config() so a company can keep more than the
     * installation default — a labour dispute reaches back further than a tidy
     * database does.
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'date',
            '<',
            now()->subMonths((int) setting('attendance.retention_months', 36))->toDateString(),
        );
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Guarded: null at a company without `leave`. */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('date', $year)->whereMonth('date', $month);
    }

    /** Days somebody was actually at work. */
    public function scopeWorked(Builder $query): Builder
    {
        return $query->whereIn('status', self::WORKED_STATUSES);
    }

    /**
     * Days nobody has answered for.
     *
     * Worth a scope of its own because the honest report is "how much of this month is
     * unknown", and it is what a payroll clerk must see before trusting a pro-rated
     * figure.
     */
    public function scopeUnknown(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NOT_MARKED);
    }

    public function isWorked(): bool
    {
        return in_array($this->status, self::WORKED_STATUSES, true);
    }

    public function isKnown(): bool
    {
        return $this->status !== self::STATUS_NOT_MARKED;
    }

    /**
     * Whether this day earns a compensatory off (phase 2a).
     *
     * A weekly off or a holiday that was nevertheless worked. Both conditions matter:
     * the status says the company did not expect work, and the minutes say work
     * happened anyway.
     */
    public function earnsCompensatoryOff(): bool
    {
        return in_array($this->status, self::NON_WORKING_STATUSES, true)
            && $this->worked_minutes > 0;
    }

    /** A day inside an approved leave, which nothing may overwrite as present. */
    public function isCoveredByLeave(): bool
    {
        return $this->leave_request_id !== null;
    }

    public static function forDate(int $employeeId, string|Carbon $date): ?self
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->first();
    }
}
