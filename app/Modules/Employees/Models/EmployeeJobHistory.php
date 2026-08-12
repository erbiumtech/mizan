<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per change to an employee's job: designation, department, who they
 * report to, how they are employed.
 *
 * The row is the fact; `employees` carries the same three values denormalised as
 * the current state so no existing query has to learn about history. Reads go
 * through `App\Modules\Employees\Services\JobHistory` rather than this model
 * directly — the "as at date X" semantics and the no-history fallback live there
 * and must not be reimplemented per call site.
 *
 * No `Auditable`: this table *is* the audit trail for these columns, and logging
 * changes to it would record the same promotion twice. History rows are written
 * once and corrected rarely; a wrong `effective_from` is fixed by editing the
 * row, and `updated_at` says one was.
 */
class EmployeeJobHistory extends Model
{
    /**
     * Laravel would pluralise this to `employee_job_histories`. "History" is the
     * mass noun here — the table is the history, not a bag of histories — and the
     * plan names the table `employee_job_history`, so the guess is overridden
     * rather than the schema bent to fit it.
     */
    protected $table = 'employee_job_history';

    protected $fillable = [
        'employee_id', 'effective_from', 'designation', 'department',
        'manager_id', 'employment_type', 'reason', 'recorded_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
    ];

    /**
     * Store the date as a date, not as midnight of one.
     *
     * Eloquent's `date` cast writes through `fromDateTime()`, which formats with
     * the connection's `Y-m-d H:i:s` — MySQL then truncates it to fit the DATE
     * column, but SQLite is typeless and keeps the whole string. So the test
     * database would hold `2026-03-01 00:00:00` where production holds
     * `2026-03-01`, and `where('effective_from', '<=', '2026-03-01')` — the exact
     * boundary every "as at date X" read hits — is true on one and false on the
     * other by plain string comparison.
     *
     * A set mutator is checked before the date handling, so this is what makes the
     * two agree. It also keeps the reads as bare `<=` against the composite index
     * rather than `whereDate()`, which would wrap the column in a function and
     * stop MySQL using it.
     */
    public function setEffectiveFromAttribute(mixed $value): void
    {
        $this->attributes['effective_from'] = $value === null
            ? null
            : Carbon::parse($value)->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Who this employee reported to from `effective_from`.
     *
     * Unconstrained in the schema, so this can resolve to null for a manager who
     * has since been deleted. That is the honest answer — the history row still
     * records that the reporting line existed — and callers must handle it, which
     * is why `JobHistory::managerOn()` returns `?Employee` rather than throwing.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
