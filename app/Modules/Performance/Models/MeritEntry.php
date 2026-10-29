<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One dated piece of evidence — docs/saas-launch-plan.md §8.1.
 *
 * Append-only, and that is the whole of what makes it evidence: a row is never
 * edited or removed, a correction is a new row of the opposite sign that names
 * the one it reverses, and the balance for a period is a sum — computed, never
 * stored, the ledger rule from accounting applied to points.
 *
 * Nothing writes a rating from this. The review form shows the period's ledger
 * beside the rating fields; the manager reads it and decides.
 */
class MeritEntry extends Model
{
    public const SOURCE_TASK = 'task_occurrence';

    public const SOURCE_LATE_MARK = 'attendance_day';

    public const SOURCE_BADGE = 'employee_badge';

    public const SOURCE_REPORT = 'daily_report';

    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id', 'occurred_on', 'points', 'source_type', 'source_id', 'reason', 'recorded_by', 'reverses_entry_id',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'points' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MeritEntry $entry): void {
            $entry->created_at ??= now();
        });

        // The ledger rule. activity_log is append-only by design and this is too;
        // an exception rather than a silent no-op, so a caller that tries learns.
        static::updating(function (): void {
            throw new LogicException('A merit entry is never edited: record a reversing entry instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('A merit entry is never deleted: record a reversing entry instead.');
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        // whereDate on both ends: the date cast stores midnight, and a plain between
        // against 'YYYY-MM-DD' would drop the last day of the window.
        return $query->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $to);
    }

    public function scopeForEmployee(Builder $query, Employee|int $employee): Builder
    {
        return $query->where('employee_id', $employee instanceof Employee ? $employee->getKey() : $employee);
    }
}
