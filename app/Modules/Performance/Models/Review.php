<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's review in one cycle.
 *
 * `final_rating` reaches no payslip, and nothing here computes a salary. A rating is an
 * opinion; a package is a versioned EmployeeSetting somebody approves. Wiring the first to
 * the second would make the appraisal a payroll instruction, and the first disagreement
 * about a rating would become a payroll incident — docs/hrms-plan.md §4.5.
 */
class Review extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SELF_SUBMITTED = 'self_submitted';

    public const STATUS_MANAGER_SUBMITTED = 'manager_submitted';

    public const STATUS_SHARED = 'shared';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    protected $fillable = [
        'review_cycle_id', 'employee_id', 'reviewer_employee_id', 'status',
        'self_rating', 'manager_rating', 'final_rating', 'strengths', 'improvements',
        'submitted_at', 'shared_at', 'acknowledged_at',
    ];

    protected $casts = [
        'self_rating' => 'integer',
        'manager_rating' => 'integer',
        'final_rating' => 'integer',
        'submitted_at' => 'datetime',
        'shared_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_employee_id');
    }

    /**
     * Whether the person it is about may read it yet.
     *
     * Submitted is not shared. A written review is a draft about somebody until a manager
     * decides to share it, and showing it before that would make honest drafting
     * impossible.
     */
    public function isVisibleToEmployee(): bool
    {
        return $this->shared_at !== null;
    }
}
