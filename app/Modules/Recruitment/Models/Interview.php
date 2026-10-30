<?php

namespace App\Modules\Recruitment\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interview extends Model
{
    use Auditable;

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_PASSED = 'passed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_NO_SHOW = 'no_show';

    /** How the round is held — the three the scheduler offers. */
    public const MODES = ['in_person' => 'In person', 'phone' => 'Phone', 'video' => 'Video'];

    /**
     * What happened, as a reader sees it.
     *
     * `pending` reads as **Scheduled**, because that is what it means to anybody looking at the
     * candidate: the round is in the diary and nobody has decided yet. "Pending" describes the state
     * of the column rather than the state of the thing, and the two screens that showed it raw made
     * a booked interview look like an outstanding task.
     */
    public const OUTCOMES = [
        self::OUTCOME_PENDING => 'Scheduled',
        self::OUTCOME_PASSED => 'Passed',
        self::OUTCOME_FAILED => 'Failed',
        self::OUTCOME_NO_SHOW => 'Did not attend',
    ];

    protected $fillable = [
        'application_id', 'round', 'scheduled_at', 'mode', 'panel',
        'location', 'outcome', 'notes', 'interviewer_employee_id', 'interviewer_user_ids',
    ];

    protected $casts = ['round' => 'integer', 'scheduled_at' => 'datetime', 'interviewer_user_ids' => 'array'];

    protected $attributes = ['round' => 1, 'mode' => 'in_person', 'outcome' => self::OUTCOME_PENDING];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_employee_id');
    }

    /** The panel's feedback — docs/saas-launch-plan.md §14.6, one row per interviewer. */
    public function feedback(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InterviewFeedback::class);
    }

    public function isDecided(): bool
    {
        return $this->outcome !== self::OUTCOME_PENDING;
    }
}
