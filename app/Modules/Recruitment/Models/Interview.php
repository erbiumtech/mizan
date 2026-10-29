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
