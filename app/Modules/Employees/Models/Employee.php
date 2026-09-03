<?php

namespace App\Modules\Employees\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\Bank;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Services\JobHistory;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

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

    /** The default employee-code prefix. Companies that want another one pass their own. */
    public const CODE_PREFIX = 'EMP-';

    /**
     * The next employee code **for this company**.
     *
     * `employees` is a tenant table, so counting here is already per-company; the
     * mistake this replaces was deriving the code from the *user* id instead. Users
     * live in the landlord database and are shared across companies, so `EMP-`.$user->id
     * numbered every company off one global sequence: a company's first employee could
     * be `EMP-47`, and no company's numbering started at one or ran consecutively.
     *
     * Taken from the highest code in use rather than from a row count, because a count
     * goes *down* when somebody leaves and is deleted — the next hire would then be
     * handed a code another row already holds, and `employee_id` is unique.
     *
     * The suffix is compared numerically in PHP rather than ordered in SQL: existing
     * data mixes widths (`EMP-47` beside `EMP-0001`), and by string order `EMP-9` sorts
     * above `EMP-10`. Anything that is not the prefix followed by digits is ignored, so
     * a company's own hand-typed codes never affect the sequence.
     */
    public static function nextEmployeeId(string $prefix = self::CODE_PREFIX): string
    {
        $highest = static::query()
            ->where('employee_id', 'like', $prefix.'%')
            ->pluck('employee_id')
            ->map(fn (?string $code): string => Str::after((string) $code, $prefix))
            ->filter(fn (string $suffix): bool => $suffix !== '' && ctype_digit($suffix))
            ->map(fn (string $suffix): int => (int) $suffix)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Insert under a freshly generated code, regenerating it if somebody else gets there first.
     *
     * Reading the highest code and inserting the next one is two steps, and two people hired
     * at the same moment both read the same highest. `employee_id` is unique, so the second
     * insert fails — and it fails on a create the user has no way to understand or retry,
     * having done nothing wrong. The window is small and the fix is simply to look again:
     * the sequence has moved on by the time we do.
     *
     * Retried on any unique violation because `employee_id` is the *only* unique index on
     * `employees` — so a duplicate-key error from inserting one can only be the code. Neither
     * MySQL nor SQLite reports the offending column in a form the other also gives (MySQL
     * names the index, SQLite the columns), so there is no portable way to narrow it further,
     * and narrowing on driver-specific text would be worse than the assumption. **Add a second
     * unique column to this table and this comment stops being true.**
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn
     */
    public static function withGeneratedCode(callable $callback, string $prefix = self::CODE_PREFIX, int $attempts = 5)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback(static::nextEmployeeId($prefix));
            } catch (UniqueConstraintViolationException $e) {
                // Rethrown rather than looped forever: if the code is still contended after
                // this many tries the cause is not a race, and hiding it would be worse.
                if ($attempt >= $attempts) {
                    throw $e;
                }
            }
        }
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

    /*
     * What kinds of employment a company records was `EMPLOYMENT_TYPES` here — a
     * constant rather than an enum column, because an enum change is a table rebuild on
     * MySQL and unsupported on SQLite, "and the list varies by company". It varies by
     * company, so it is now a list the company writes: `employees.employment_type`,
     * declared with its shipped values in app/Modules/Employees/module.php and read
     * through `options()`. Same reasoning, one step further; the reason it was a
     * constant — one list, not one per screen — is why it is one declaration.
     */

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

        // Whose record this is, as the database has it — NOT as this save would leave it.
        //
        // `$this->user_id` is the *pending* value while saving, and reading it here made the column that
        // decides the answer part of the question. Both directions were wrong:
        //
        //  - Linking an existing employee to the actor's own login (`user_id` = me) read as a self-service
        //    edit. `user_id` is not requestable, so the change was filtered out, `$changes` came out empty,
        //    and the save was reverted wholesale — the link, and everything else in the same save, silently
        //    discarded.
        //  - The mirror: an employee editing their own record and moving `user_id` to somebody else read as
        //    NOT self-service, so that save applied directly and skipped approval for every other field in
        //    it.
        //
        // The original value is the only one that answers "is this person editing their own record".
        $ownedBy = $this->getOriginal('user_id');

        $selfService = ! static::$skipApprovalRouting
            && $this->exists
            && $actor
            && $ownedBy !== null
            && (int) $ownedBy === (int) $actor->id
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
        // Loaded explicitly rather than read lazily. This is called from selects, columns and labels
        // all over the panel, on records that arrive from anywhere, so it cannot assume the caller
        // eager-loaded the user — and reading it lazily is a violation the moment the guard is on.
        //
        // Note what this does *not* fix: one query per employee is still one query per employee, and
        // `loadMissing` only makes that explicit rather than fatal. Anywhere this is called over a
        // list — a table column, a select's options — the query behind the list should eager-load
        // `user`, and this stays as the safety net for the single-record callers.
        $this->loadMissing('user');

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

    /*
     * The four project relations that used to be declared here are registered by Projects instead —
     * `ProjectsServiceProvider::contributeToEmployees()`, through `Model::resolveRelationUsing()`.
     * `$employee->projects()`, `->managedProjects()` and `->secondaryProjects()` all still work, and now
     * exist only when the Projects module does, which is the honest answer.
     *
     * The reason is packaging, not taste: `projects` requires `employees`, so an Employee naming a
     * Project made the pair a cycle, and a cycle cannot be expressed as a composer dependency at all.
     * A string class name would have hidden that from the lint while leaving it true — see
     * docs/module-packaging-plan.md, phase 0, on the difference.
     *
     * `currentProjects()` went with them and was not re-registered: nothing called it.
     */

    /** The employee record of the signed-in user, if they have one. */
    public static function forUser(?int $userId = null): ?self
    {
        $userId ??= auth()->id();

        return $userId ? static::where('user_id', $userId)->first() : null;
    }
}
