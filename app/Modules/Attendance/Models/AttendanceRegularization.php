<?php

namespace App\Modules\Attendance\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "I was here that day" — the way out of `not_marked`.
 *
 * `not_marked` is only honest if there is a route out of it. Without one it
 * accumulates into precisely the "month nobody filled in" the status exists to
 * distinguish, and the distinction stops meaning anything.
 *
 * The route is not an admin editing rows. It is the EmployeeChangeRequest pattern
 * applied to a single day — the pattern docs/hrms-plan.md §1 names as the one every HR
 * request should copy — so the correction is auditable rather than a silent overwrite,
 * and the original values stay on the request.
 *
 * This is the highest-frequency request in the family after leave itself, and the only
 * one an employee raises about their own past.
 */
class AttendanceRegularization extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['date'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    /** Who may decide somebody else's correction. */
    public const APPROVE_PERMISSION = 'AttendanceRegularizationApprove';

    protected $fillable = [
        'employee_id', 'date', 'requested_status', 'requested_check_in_at',
        'requested_check_out_at', 'reason', 'status', 'submitted_by',
        'decided_by', 'decided_at', 'refusal_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'requested_check_in_at' => 'datetime',
        'requested_check_out_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Same two-sided ownership test leave uses: filed it, or it is about them. */
    public function belongsToUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->submitted_by === $user->getKey()
            || ($this->employee?->user_id !== null && $this->employee->user_id === $user->getKey());
    }
}
