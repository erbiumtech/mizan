<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What somebody did today, in their own words — docs/saas-launch-plan.md §8.3.
 *
 * The verdict columns are a model's opinion and advisory; the manager's
 * decision is what reaches the ledger. Until a manager acts, the employee
 * sees that the report was filed and not what a model thought of it.
 */
class DailyReport extends Model
{
    public const VERDICT_SUBSTANTIVE = 'substantive';

    public const VERDICT_THIN = 'thin';

    public const VERDICT_OFF_TOPIC = 'off_topic';

    public const VERDICTS = [self::VERDICT_SUBSTANTIVE, self::VERDICT_THIN, self::VERDICT_OFF_TOPIC];

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_OVERRIDDEN = 'overridden';

    protected $fillable = [
        'employee_id', 'report_date', 'body', 'submitted_at',
        'verdict', 'verdict_summary', 'verdict_questions', 'verdict_at', 'verdict_model',
        'manager_decision', 'decided_by', 'decided_at', 'manager_note', 'merit_entry_id',
    ];

    protected $casts = [
        'report_date' => 'date',
        'submitted_at' => 'datetime',
        'verdict_questions' => 'array',
        'verdict_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function meritEntry(): BelongsTo
    {
        return $this->belongsTo(MeritEntry::class);
    }

    public function hasVerdict(): bool
    {
        return $this->verdict !== null;
    }

    public function isDecided(): bool
    {
        return $this->manager_decision !== null;
    }
}
