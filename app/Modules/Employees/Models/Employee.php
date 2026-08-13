<?php

namespace App\Modules\Employees\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Bank;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Services\JobHistory;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use Auditable, HasCustomFields;

    protected $fillable = [
        'user_id', 'name', 'manager_id', 'employee_id', 'phone', 'secondary_phone', 'personal_email', 'gender',
        'is_active', 'designation', 'department', 'employment_type',
        // What this employee's time bills at, when the project does not say. Nullable
        // and unused by any company that bills by headcount rather than by the hour.
        'hourly_rate',
        'left_on', 'leaving_reason', 'notice_served_until',
        'date_of_joining', 'date_of_birth', 'nic', 'nic_front', 'nic_back', 'bank_id', 'bank_code', 'bank_short_code', 'bank_account_no', 'iban_no',
        'address_line_1', 'address_line_2',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
        'date_of_birth' => 'date',
        // When they left, if they have. Not a substitute for `is_active`, which
        // stays the flag every query filters on — see the migration.
        'left_on' => 'date',
        'notice_served_until' => 'date',
    ];

    protected static function booted()
    {
        static::saving(function ($employee) {
            /*
             * The two code columns are a copy of the chosen directory bank, kept beside the
             * relation because the bank file reads them per row.
             *
             * "No bank chosen" is not the same as "no bank", though: the directory lists the
             * banks we transfer *out* to, so an employee who banks with us has none to
             * choose and carries a short code of their own. Blanking the copies whenever
             * bank_id was empty erased exactly that — the field could be filled in and the
             * value never survived the save, which is half of why an own-bank employee could
             * not be recorded at all.
             *
             * So they are cleared only when they describe a bank that has just been removed
             * and nothing was put in its place.
             */
            if ($employee->bank_id) {
                $bank = Bank::find($employee->bank_id);
                if ($bank) {
                    $employee->bank_code = $bank->bank_code;
                    $employee->bank_short_code = $bank->bank_short_code;
                }
            } elseif ($employee->isDirty('bank_id')
                && ! $employee->isDirty('bank_short_code')
                && ! $employee->isDirty('bank_code')) {
                $employee->bank_code = null;
                $employee->bank_short_code = null;
            }

            $employee->routeChangesThroughApproval();
        });

        // Every change to a job fact becomes a history row, whatever made it.
        //
        // On the model rather than in the Filament resource on purpose: these
        // columns are written by the employee form, by an approved
        // EmployeeChangeRequest, by the CSV importer and by tinker, and a hook on
        // one of those four would leave the other three overwriting history
        // silently — which is the exact failure App\Modules\Employees\Services\JobHistory
        // exists to end. `updated` rather than `saving`, because a row should
        // record a change that actually landed.
        static::updated(function (Employee $employee) {
            if (static::$skipJobHistory || ! $employee->wasChanged(self::JOB_FACTS)) {
                return;
            }

            app(JobHistory::class)->captureCurrent($employee);
        });

        static::deleting(function ($employee) {
            // Keep the hierarchy connected when a manager is removed: reparent
            // their direct reports to the manager's own manager (or detach to
            // null if they were at the top).
            self::where('manager_id', $employee->id)
                ->update(['manager_id' => $employee->manager_id]);
        });
    }

    /**
     * The job facts that are worth a history row — the columns whose past values
     * an approval chain or a cost report has to be able to reconstruct.
     *
     * Deliberately not every column. A corrected phone number has no history
     * anybody needs, and recording one would bury the three that matter.
     *
     * @var array<int, string>
     */
    public const JOB_FACTS = ['designation', 'department', 'manager_id', 'employment_type'];

    /**
     * What kinds of employment a company records, as value => label.
     *
     * A constant rather than an enum column: an enum change is a table rebuild on
     * MySQL and unsupported on SQLite, and the list varies by company. Kept here
     * rather than in the form so the form and any future report agree — the
     * lesson `Company::TYPE_LABELS` records, where two screens each wrote their
     * own pair and disagreed.
     *
     * @var array<string, string>
     */
    public const EMPLOYMENT_TYPES = [
        'permanent' => 'Permanent',
        'contract' => 'Contract',
        'probation' => 'Probation',
        'intern' => 'Intern',
    ];

    /** Set while JobHistory writes its own denormalised sync back to this row. */
    protected static bool $skipJobHistory = false;

    /**
     * Run a write without it recording a history row.
     *
     * For exactly one caller: `JobHistory::record()` writes the row itself and
     * then projects it onto these columns, so the hook above would file a
     * duplicate for the same change — and, because that sync saves the employee,
     * would do it on every save in a loop.
     */
    public static function withoutJobHistory(callable $callback): mixed
    {
        static::$skipJobHistory = true;

        try {
            return $callback();
        } finally {
            static::$skipJobHistory = false;
        }
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

    /**
     * Every recorded change to this employee's job, newest first.
     *
     * Newest first because the one question this relation is loaded for is "what
     * is the latest row at or before date X", and `->first()` after a `where`
     * answers it without a second sort. It is emptiest exactly where it matters —
     * every employee that predates this feature has no rows at all — so read
     * through `App\Modules\Employees\Services\JobHistory`, which falls back to the
     * current columns on this record instead of answering null.
     */
    public function jobHistory(): HasMany
    {
        return $this->hasMany(EmployeeJobHistory::class)->orderByDesc('effective_from');
    }

    /** Display label used in selects/columns: "EMP-1 - John Doe". */
    public function getDisplayLabelAttribute(): string
    {
        return trim($this->employee_id.' - '.$this->fullName(), ' -');
    }

    /**
     * The person's name, wherever it lives.
     *
     * The linked user first, because for anybody who signs in that record is the
     * one source of truth and this must not drift from it. The `name` column is
     * the fallback for staff with no login — a household's driver or cook — who
     * have no user to take a name from.
     */
    public function fullName(): string
    {
        return (string) ($this->user?->name ?? $this->name ?? '');
    }

    /** Employed here, but never signs in. */
    public function hasLogin(): bool
    {
        return $this->user_id !== null;
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
