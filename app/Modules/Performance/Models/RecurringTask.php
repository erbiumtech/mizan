<?php

namespace App\Modules\Performance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something somebody has to do every day, week or month — docs/saas-launch-plan.md §8.2.
 *
 * Assigned to one employee, a department, a location, everybody, or (plan
 * §8.9) a rule. `tasks:roll` turns it into one occurrence per person per due
 * date; a miss past the grace is a merit debit of `penalty_points`, and
 * `penalty_amount` is the rupee figure `tasks:settle` sums for payroll.
 */
class RecurringTask extends Model
{
    use Auditable;

    public const ASSIGNEE_ALL = 'all';

    public const ASSIGNEE_EMPLOYEE = 'employee';

    public const ASSIGNEE_DEPARTMENT = 'department';

    public const ASSIGNEE_LOCATION = 'location';

    public const ASSIGNEE_RULE = 'rule';

    public const CADENCE_DAILY = 'daily';

    public const CADENCE_WEEKLY = 'weekly';

    public const CADENCE_MONTHLY = 'monthly';

    /** The daily report (plan §8.3) is a task of this kind; nothing else may use it. */
    public const KIND_DAILY_REPORT = 'daily_report';

    protected $fillable = [
        'title', 'description', 'assignee_kind', 'assignee_ref', 'assignee_rule', 'cadence', 'due_time',
        'due_weekday', 'due_day_of_month', 'working_days_only', 'grace_hours', 'penalty_points', 'penalty_amount', 'is_active',
    ];

    protected $casts = [
        'assignee_rule' => 'array',
        'due_weekday' => 'integer',
        'due_day_of_month' => 'integer',
        'working_days_only' => 'boolean',
        'grace_hours' => 'integer',
        'penalty_points' => 'integer',
        'penalty_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'assignee_kind' => self::ASSIGNEE_ALL,
        'cadence' => self::CADENCE_DAILY,
        'due_time' => '17:00:00',
        'working_days_only' => true,
        'grace_hours' => 0,
        'penalty_points' => 0,
        'is_active' => true,
    ];

    public function occurrences(): HasMany
    {
        return $this->hasMany(TaskOccurrence::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Does this task fall due on a given date, by its cadence alone? */
    public function isDueOn(Carbon $date): bool
    {
        return match ($this->cadence) {
            self::CADENCE_WEEKLY => $date->dayOfWeekIso === (int) ($this->due_weekday ?? 1),
            self::CADENCE_MONTHLY => $date->day === min((int) ($this->due_day_of_month ?? 1), $date->daysInMonth),
            default => true,
        };
    }

    public function dueAtOn(Carbon $date): Carbon
    {
        return Carbon::parse($date->toDateString().' '.($this->due_time ?: '17:00:00'));
    }

    /**
     * Who this task is for today: active employees, narrowed by the assignee.
     * A rule assignee resolves to nobody until the builder lands (plan §8.9).
     *
     * @return Collection<int, Employee>
     */
    public function assignees(): Collection
    {
        $employees = Employee::query()->where('is_active', true);

        match ($this->assignee_kind) {
            self::ASSIGNEE_EMPLOYEE => $employees->whereKey((int) $this->assignee_ref),
            self::ASSIGNEE_DEPARTMENT => $employees->where('department', (string) $this->assignee_ref),
            self::ASSIGNEE_LOCATION => $employees->where('location_id', (int) $this->assignee_ref),
            self::ASSIGNEE_RULE => $employees->whereRaw('1 = 0'),
            default => null,
        };

        return $employees->orderBy('employee_id')->get();
    }

    public function assigneeLabel(): string
    {
        return match ($this->assignee_kind) {
            self::ASSIGNEE_EMPLOYEE => (string) (Employee::query()->whereKey($this->assignee_ref)->first()?->display_label ?? 'One employee'),
            self::ASSIGNEE_DEPARTMENT => (string) $this->assignee_ref,
            self::ASSIGNEE_LOCATION => (string) (\App\Modules\Core\Models\WorkLocation::query()->whereKey($this->assignee_ref)->value('name') ?? 'A location'),
            self::ASSIGNEE_RULE => 'By rule',
            default => 'Everyone',
        };
    }
}
