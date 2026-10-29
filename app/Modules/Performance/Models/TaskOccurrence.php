<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's instance of a recurring task on one date — docs/saas-launch-plan.md §8.2.
 */
class TaskOccurrence extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MISSED = 'missed';

    public const STATUS_EXCUSED = 'excused';

    protected $fillable = [
        'recurring_task_id', 'employee_id', 'due_on', 'due_at', 'completed_at', 'completed_note',
        'missed_at', 'excused_by', 'excused_at', 'excused_reason', 'merit_entry_id',
    ];

    protected $casts = [
        'due_on' => 'date',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'missed_at' => 'datetime',
        'excused_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class, 'recurring_task_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function excusedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excused_by');
    }

    public function meritEntry(): BelongsTo
    {
        return $this->belongsTo(MeritEntry::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->completed_at !== null => self::STATUS_COMPLETED,
            $this->excused_at !== null => self::STATUS_EXCUSED,
            $this->missed_at !== null => self::STATUS_MISSED,
            default => self::STATUS_OPEN,
        };
    }

    public function isOpen(): bool
    {
        return $this->status() === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at')->whereNull('missed_at');
    }

    public function scopeMissedUnexcused(Builder $query): Builder
    {
        return $query->whereNotNull('missed_at')->whereNull('excused_at');
    }
}
