<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A delivery project: a team of employees with dated stints, a primary and
 * secondary manager, and its deployment environments (prod/qual/dev).
 */
class Project extends Model
{
    use Auditable, HasCustomFields;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED => 'Planned',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_ON_HOLD => 'On hold',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'code', 'name', 'description', 'status',
        'manager_employee_id', 'secondary_employee_id',
        'start_date', 'end_date',
    ];

    protected $attributes = [
        'status' => self::STATUS_PLANNED,
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Project $project): void {
            // Guard the designation at the model level too, so a seeder or
            // console script can't set the same person as both managers.
            if ($project->secondary_employee_id
                && (int) $project->secondary_employee_id === (int) $project->manager_employee_id) {
                throw new InvalidArgumentException('The secondary manager must be a different employee from the primary manager.');
            }
        });
    }

    public function employees(): BelongsToMany
    {
        // Table named explicitly: Laravel's alphabetical default would be
        // employee_project.
        return $this->belongsToMany(Employee::class, 'project_employee')
            ->withPivot(['id', 'role', 'allocation_pct', 'from_date', 'to_date'])
            ->withTimestamps();
    }

    /** Assignments that have not ended yet. */
    public function currentEmployees(): BelongsToMany
    {
        return $this->employees()->where(function ($query) {
            $query->whereNull('project_employee.to_date')
                ->orWhereDate('project_employee.to_date', '>=', today()->toDateString());
        });
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function secondaryManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'secondary_employee_id');
    }

    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class);
    }

    public function environment(string $kind): ?ProjectEnvironment
    {
        return $this->environments->firstWhere('kind', $kind)
            ?? $this->environments()->where('kind', $kind)->first();
    }

    /** The primary and secondary manager, whichever are set. */
    public function managers(): Collection
    {
        return collect([$this->manager, $this->secondaryManager])->filter()->values();
    }

    /** Users to notify about this project (managers, resolved to accounts). */
    public function notifiableUsers(): Collection
    {
        return $this->managers()
            ->map(fn (Employee $employee) => $employee->user)
            ->filter()
            ->unique('id')
            ->values();
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Add an employee to the team. Rejects a second open stint for the same
     * person, which the unique index alone would only catch on the same day.
     */
    public function assign(Employee $employee, array $pivot = []): void
    {
        $from = $pivot['from_date'] ?? today()->toDateString();

        if ($this->hasOpenAssignment($employee)) {
            throw new InvalidArgumentException('That employee already has an open assignment on this project. End it before adding a new one.');
        }

        // The unique index would otherwise surface as a raw constraint error.
        $duplicateStart = $this->employees()
            ->newPivotStatement()
            ->where('project_id', $this->id)
            ->where('employee_id', $employee->id)
            ->whereDate('from_date', $from)
            ->exists();

        if ($duplicateStart) {
            throw new InvalidArgumentException('That employee already has an assignment on this project starting '.$from.'. Pick a different start date.');
        }

        $this->employees()->attach($employee->id, [
            'role' => $pivot['role'] ?? null,
            'allocation_pct' => $pivot['allocation_pct'] ?? null,
            'from_date' => $from,
            'to_date' => $pivot['to_date'] ?? null,
        ]);
    }

    /** Close an employee's open stint instead of deleting its history. */
    public function endAssignment(Employee $employee, ?string $on = null): void
    {
        $this->employees()
            ->newPivotStatement()
            ->where('project_id', $this->id)
            ->where('employee_id', $employee->id)
            ->whereNull('to_date')
            ->update(['to_date' => $on ?? today()->toDateString(), 'updated_at' => now()]);
    }

    public function hasOpenAssignment(Employee $employee): bool
    {
        return $this->employees()
            ->newPivotStatement()
            ->where('project_id', $this->id)
            ->where('employee_id', $employee->id)
            ->where(function ($query) {
                $query->whereNull('to_date')->orWhereDate('to_date', '>=', today()->toDateString());
            })
            ->exists();
    }

    /** Worst environment health on the project, for at-a-glance columns. */
    public function worstEnvironmentStatus(): string
    {
        $statuses = $this->environments->pluck('health_status');

        if ($statuses->contains(ProjectEnvironment::HEALTH_DOWN)) {
            return ProjectEnvironment::HEALTH_DOWN;
        }

        if ($statuses->contains(ProjectEnvironment::HEALTH_UP)) {
            return ProjectEnvironment::HEALTH_UP;
        }

        return ProjectEnvironment::HEALTH_UNKNOWN;
    }
}
