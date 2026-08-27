<?php

namespace App\Support\Reporting;

use App\Support\ModuleMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One reportable subject — `docs/reports-expansion-plan.md` Phase 6, items 1 and 2.
 *
 * > **The dataset registry**, one declaration per reportable subject: journal lines, invoices and their
 * > lines, payslips and their components, employees, stock movements, timesheet entries, tickets,
 * > opportunities, leave days. Each declares its label, its **module**, the **permission** it needs, its base
 * > query *through the Eloquent model* so every global scope and tenancy applies, and then the columns and
 * > the filters it offers. **The registry is the boundary**: no raw SQL, no table it has not named, no
 * > relation it has not declared.
 *
 * **Why the boundary is a declaration and not a validator.** A builder is a feature where a user composes
 * the query, so the only defensible design is one where the *set of expressible queries* is small and stated
 * in advance. Nothing here accepts a column name from a request: a definition names a column *key*, this
 * class turns that key into the one column the dataset declared for it, and a key it does not recognise
 * resolves to nothing. A validator would have to be right every time; a registry has to be wrong on purpose.
 *
 * **`query()` goes through the model, and that sentence carries the tenancy.** Every model here is a
 * `TenantModel` or carries a company scope, so `Invoice::query()` is already this company's invoices, already
 * excluding whatever a global scope excludes. A builder that assembled a raw query over the invoices table
 * would be correct on a single-company install and a cross-tenant leak on this one.
 *
 * (That sentence used to name the call it was warning about, in backticks. `TenantConnectionGuardTest` scans
 * this directory's *source text* for it, so the warning read as the offence and failed the build — a comment
 * must not imitate the thing it forbids.)
 *
 * **Row-level access is inherited, not re-implemented** (item 2). A dataset over a subject with an employee
 * dimension applies `EmployeeAccess` in `access()`, exactly as the resource over the same rows does — so the
 * builder cannot become the way around the manager hierarchy. Three datasets do this and it is one line each,
 * which is the point: re-implementing the walk is what would eventually disagree with the resources.
 *
 * **The module is derived rather than declared**, though the item lists it. `ModuleMap::moduleFor()` reads it
 * from the namespace, so `App\Modules\Payroll\Reporting\PayslipDataset` is Payroll's whatever a method says —
 * and a declaration that can disagree with where the file lives is a declaration that eventually will.
 */
abstract class Dataset
{
    /** What this subject is called in the builder's list. */
    abstract public static function label(): string;

    /**
     * One sentence on what a row is.
     *
     * Worth requiring, because the difference between "invoices" and "invoice lines" is the difference
     * between two reports that both look plausible and only one of which answers the question — and somebody
     * choosing between them in a dropdown has nothing else to go on.
     */
    abstract public static function description(): string;

    /** @return class-string<Model> */
    abstract public static function model(): string;

    /**
     * The permission needed to report on this subject, *in addition* to `ReportView`.
     *
     * The subject's own view permission, not a reporting one: item 6 notes that "a company-wide custom report
     * over payslips is a payroll leak", and the thing standing between somebody and that leak has to be the
     * same permission that stands between them and the payslips themselves. `ReportView` governs reading a
     * report at all and is checked by the pane; this governs which subjects a person may build over.
     */
    abstract public static function permission(): string;

    /**
     * The date column a period applies to, or null for a subject that has no period.
     *
     * Asked of every dataset because item 5's cost guard is "a mandatory period filter **or** an explicit row
     * cap": a period cannot be made mandatory over a subject that has not said which of its dates bounds it,
     * and the question is better answered when the dataset is written than when a report times out.
     *
     * **Null is a real answer and two kinds of subject give it.** An employee is a *state*, not an event — a
     * mandatory period over `date_of_joining` would answer "who joined this quarter" for somebody who asked
     * for a headcount. And a payslip's period is not a date at all: `payslips.month` holds a month *name*
     * ('January'), so it neither orders nor bounds, and what stands in for a period there is a fiscal year and
     * a month, both selects. Those datasets pay item 5's row cap instead, which is the branch the plan
     * deliberately left open.
     *
     * **A period may be reached through a declared relation**, written `relation.column`. A journal line has
     * no date of its own — `journal_entry_lines` has never had one — so bounding it means bounding its entry,
     * which is `whereHas` on a relation the model declares rather than a join this builder writes. It costs a
     * subquery, which is why a dataset with a date of its own should name that one.
     */
    abstract public static function periodColumn(): ?string;

    /** @return array<int, DatasetColumn> */
    abstract public static function columns(): array;

    /** @return array<int, DatasetFilter> */
    abstract public static function filters(): array;

    /**
     * The rows, before anything a report asks for.
     *
     * Through the model, so tenancy and every global scope apply, and through `access()`, so row-level
     * scoping does too.
     */
    public static function query(): Builder
    {
        return static::access(static::model()::query());
    }

    /**
     * Row-level access, for the subjects that have any.
     *
     * The default is "nothing further", which is right for invoices and journal lines: those are gated by
     * permission, not by hierarchy, and the resources over them do the same. Payslips, timesheet entries and
     * leave days override it.
     */
    protected static function access(Builder $query): Builder
    {
        return $query;
    }

    /** Which module owns this, read from the namespace rather than declared. */
    public static function module(): string
    {
        return (string) ModuleMap::moduleFor(static::class);
    }

    /**
     * The stable key a saved definition stores.
     *
     * `ModuleMap::alias()`, for the reason item 3 gives: a dataset class that moves between directories must
     * not break every report saved over it. Same indirection as a model's morph alias, same lock file.
     */
    public static function key(): string
    {
        return ModuleMap::alias(static::class);
    }

    /** Whether this company has the module and this person the permission. */
    public static function isAvailable(): bool
    {
        return modules()->enabled(static::module())
            && (bool) auth()->user()?->can(static::permission());
    }

    /** One declared column by key, or none — which is how an unrecognised key stops being dangerous. */
    public static function column(string $key): ?DatasetColumn
    {
        foreach (static::columns() as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /** One declared filter by key, or none. */
    public static function filter(string $key): ?DatasetFilter
    {
        foreach (static::filters() as $filter) {
            if ($filter->key === $key) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * The table the rows come from.
     *
     * Read off the model rather than declared, so "no table it has not named" is enforceable: the boundary
     * test compares every column and filter against this table's real columns.
     */
    public static function table(): string
    {
        return (new (static::model()))->getTable();
    }
}
