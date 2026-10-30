<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'review_cycle_id', 'review_template_id', 'employee_id', 'reviewer_employee_id', 'status',
        'self_rating', 'manager_rating', 'final_rating', 'promotion_recommended', 'promotion_designation',
        'strengths', 'improvements', 'submitted_at', 'shared_at', 'acknowledged_at',
        'self_submitted_at', 'self_comments',
    ];

    protected $casts = [
        'self_rating' => 'integer',
        'manager_rating' => 'integer',
        'final_rating' => 'integer',
        'promotion_recommended' => 'boolean',
        'submitted_at' => 'datetime',
        'shared_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'self_submitted_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    /** The salary proposal this appraisal raised, if any — plan §7.10. */
    public function incrementRequest(): HasOne
    {
        return $this->hasOne(EmployeeChangeRequest::class, 'review_id')->latestOfMany();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReviewTemplate::class, 'review_template_id');
    }

    /** The per-competency ratings — docs/saas-launch-plan.md §14.6. */
    public function ratings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReviewRating::class);
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
    /**
     * The employee's half is open — docs/saas-launch-plan.md §14.6: the cycle asks for a self-review
     * and is running, and nobody has submitted anything yet. The manager's submission closes it too:
     * once the reviewer has decided, a late self-rating would read as an answer to their verdict.
     */
    public function awaitsSelfReview(): bool
    {
        $cycle = $this->cycle;

        return $cycle !== null
            && $cycle->includes_self_review
            && $cycle->status === ReviewCycle::STATUS_OPEN
            && $this->self_submitted_at === null
            && $this->submitted_at === null
            && $this->shared_at === null;
    }

    public function scopeAwaitingSelfReview(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereNull('self_submitted_at')
            ->whereNull('submitted_at')
            ->whereNull('shared_at')
            ->whereHas('cycle', fn ($cycle) => $cycle->where('status', ReviewCycle::STATUS_OPEN)->where('includes_self_review', true));
    }

    /** Whether the self-rating is the employee's to give — so nobody else may write it. */
    public function selfRatingIsTheEmployees(): bool
    {
        return ($this->cycle?->includes_self_review ?? false) && $this->employee?->user_id !== null;
    }

    public function isVisibleToEmployee(): bool
    {
        return $this->shared_at !== null;
    }
}
