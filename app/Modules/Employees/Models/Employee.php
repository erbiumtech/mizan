<?php

namespace App\Modules\Employees\Models;

use App\Modules\Accounting\Models\Bank;
use App\Models\Concerns\HasCustomFields;
use App\Modules\Projects\Models\Project;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use Auditable, HasCustomFields;

    protected $fillable = [
        'user_id', 'manager_id', 'employee_id', 'phone', 'secondary_phone', 'personal_email', 'gender',
        'is_active', 'designation', 'department',
        'date_of_joining', 'date_of_birth', 'nic', 'nic_front', 'nic_back', 'bank_id', 'bank_code', 'bank_short_code', 'bank_account_no', 'iban_no',
        'address_line_1', 'address_line_2',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
        'date_of_birth' => 'date',
    ];

    protected static function booted()
    {
        static::saving(function ($employee) {
            if ($employee->bank_id) {
                $bank = Bank::find($employee->bank_id);
                if ($bank) {
                    $employee->bank_code = $bank->bank_code;
                    $employee->bank_short_code = $bank->bank_short_code;
                }
            } else {
                $employee->bank_code = null;
                $employee->bank_short_code = null;
            }

            $employee->routeChangesThroughApproval();
        });

        static::deleting(function ($employee) {
            // Keep the hierarchy connected when a manager is removed: reparent
            // their direct reports to the manager's own manager (or detach to
            // null if they were at the top).
            self::where('manager_id', $employee->id)
                ->update(['manager_id' => $employee->manager_id]);
        });
    }

    /** Set while an approved request is being written, to avoid re-routing it. */
    protected static bool $skipApprovalRouting = false;

    /**
     * Run a write that must land directly, skipping the self-service
     * interception below.
     *
     * Applying an approved request must not depend on who happens to be logged
     * in: the approval is the authority. Without this, approving a request while
     * the requester is still the authenticated user would file a second request
     * instead of writing the change.
     */
    public static function withoutApprovalRouting(callable $callback): mixed
    {
        static::$skipApprovalRouting = true;

        try {
            return $callback();
        } finally {
            static::$skipApprovalRouting = false;
        }
    }

    /**
     * Self-service edits become a pending EmployeeChangeRequest instead
     * of touching the record; approvers' edits apply directly. The
     * transient user_name / user_email attributes write to the linked
     * user (directly for approvers, via the request for employees).
     */
    protected function routeChangesThroughApproval(): void
    {
        $userChanges = [];

        foreach (['user_name' => 'name', 'user_email' => 'email'] as $key => $column) {
            if (array_key_exists($key, $this->attributes)) {
                $value = $this->attributes[$key];
                unset($this->attributes[$key]);

                if ($value !== null && $value !== $this->user?->{$column}) {
                    $userChanges[$key] = $value;
                }
            }
        }

        $actor = auth()->user();

        $selfService = ! static::$skipApprovalRouting
            && $this->exists
            && $actor
            && $actor->id === $this->user_id
            && ! $actor->hasAnyRole(['Administrator', 'Manager', 'CEO']);

        if (! $selfService) {
            if ($userChanges && $this->user_id) {
                User::where('id', $this->user_id)->update([
                    'name' => $userChanges['user_name'] ?? $this->user?->name,
                    'email' => $userChanges['user_email'] ?? $this->user?->email,
                ]);
            }

            return;
        }

        $changes = collect($this->getDirty())
            ->only(EmployeeChangeRequest::ALLOWED_FIELDS)
            ->merge($userChanges);

        if ($changes->isEmpty()) {
            // Nothing requestable changed; also drop any non-allowed edits.
            $this->setRawAttributes($this->getRawOriginal());

            return;
        }

        EmployeeChangeRequest::create([
            'employee_id' => $this->id,
            'requested_by' => $actor->id,
            'requested_changes' => $changes->all(),
            'original_values' => $changes->keys()->mapWithKeys(fn ($key) => [
                $key => match ($key) {
                    'user_name' => $this->user?->name,
                    'user_email' => $this->user?->email,
                    default => $this->getRawOriginal($key),
                },
            ])->all(),
        ]);

        // Leave the record untouched until the request is approved.
        $this->setRawAttributes($this->getRawOriginal());
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(EmployeeSetting::class);
    }

    /**
     * Deliberately outside the membership scope the panel puts on users.
     *
     * An employee's user is this company's own record — it is what names every
     * payslip and MPR in its history — and it has to keep resolving after the
     * person is removed from the company (Users page → Remove from company) or
     * their access moves elsewhere. Scoped, the relation would come back null and
     * the row would read as a nameless employee.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->acrossCompanies();
    }

    /** The employee this one reports to (self-referential; nullable). */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /** Employees who report directly to this one. */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /** Display label used in selects/columns: "EMP-1 - John Doe". */
    public function getDisplayLabelAttribute(): string
    {
        return trim($this->employee_id.' - '.($this->user?->name ?? ''), ' -');
    }

    public function changeRequests()
    {
        return $this->hasMany(EmployeeChangeRequest::class);
    }

    /** Projects this employee is (or was) assigned to, with the stint pivot. */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_employee')
            ->withPivot(['id', 'role', 'allocation_pct', 'from_date', 'to_date'])
            ->withTimestamps();
    }

    /** Assignments that have not ended yet. */
    public function currentProjects(): BelongsToMany
    {
        return $this->projects()->where(function ($query) {
            $query->whereNull('project_employee.to_date')
                ->orWhereDate('project_employee.to_date', '>=', today()->toDateString());
        });
    }

    /** Projects where this employee is the primary manager. */
    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'manager_employee_id');
    }

    /** Projects where this employee is the secondary manager / stand-in. */
    public function secondaryProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'secondary_employee_id');
    }

    /** The employee record of the signed-in user, if they have one. */
    public static function forUser(?int $userId = null): ?self
    {
        $userId ??= auth()->id();

        return $userId ? static::where('user_id', $userId)->first() : null;
    }
}
