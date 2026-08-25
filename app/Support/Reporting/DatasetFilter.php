<?php

namespace App\Support\Reporting;

use Closure;
use InvalidArgumentException;

/**
 * One filter a dataset offers — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **The four kinds are the ones the thirty coded reports actually needed**, which is the extraction the plan
 * asks for rather than a guess:
 *
 *  - **a date range.** Every report in Phases 1–3 has a date, and `ReportPeriod` resolves it. This is that,
 *    made explicit — and it is also the guard item 5 asks for, because a dataset naming its period column is
 *    a dataset a mandatory period can be enforced on;
 *  - **a select.** `ReportPane::ASKS` has three of these (`account`, `budget`, `month`), and Phases 2–3 added
 *    the same shape a dozen times over: a project, an employee, a status, a category;
 *  - **a search.** `FindTransactions` is the coded report that asks for one, and it is the filter people
 *    reach for when they know the reference and not the date;
 *  - **a flag.** Billable or not, paid or not, active or not — three columns in Phases 2–3 that a select over
 *    "Yes / No" would answer clumsily.
 *
 * Nothing else earned a place. A "between two numbers" filter looks obvious and no coded report needed one; a
 * relative period ("last 90 days") is `DashboardPeriod`'s job and belongs to a dashboard rather than a saved
 * report, whose whole point is that it is filed for a month somebody names.
 *
 * **A filter names a column on the dataset's own table and nothing else.** Not a relation, not an expression:
 * item 1 is explicit that "the registry is the boundary — no raw SQL, no table it has not named", and a
 * filter is the part of a report where a user's input reaches the query. Filtering on a related value is
 * filtering on its foreign key, which is a select over declared options.
 *
 * **Options are a closure, evaluated when the builder is drawn.** They are rows — accounts, projects, leave
 * types — so a static list would be stale the day somebody adds one, and a closure keeps them inside whatever
 * tenancy and access scoping the model already applies.
 */
final class DatasetFilter
{
    public const DATE_RANGE = 'date_range';

    public const SELECT = 'select';

    public const SEARCH = 'search';

    public const FLAG = 'flag';

    public const KINDS = [self::DATE_RANGE, self::SELECT, self::SEARCH, self::FLAG];

    /**
     * @param  string|null  $column  a column on the dataset's own table
     * @param  array<int, string>  $columns  the columns a search looks in
     * @param  Closure|null  $options  `fn (): array<string|int, string>`
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $kind,
        public readonly ?string $column = null,
        public readonly array $columns = [],
        public readonly ?Closure $options = null,
    ) {}

    /**
     * A period, bounded by one date column.
     *
     * The dataset's own `periodColumn()` is usually this one, and the two are separate on purpose: a dataset
     * can offer a second date to filter on — an invoice has a date and a due date — while only one of them is
     * the period a mandatory range applies to.
     *
     * **The column may be `relation.column`**, which is how a journal line gets a period: the line has no
     * date, its entry does, and `whereHas('journalEntry', …)` bounds it through a relation the model declares.
     * A subquery costs more than a local column, so a dataset with its own date names that one.
     *
     * **Only a real date column.** `payslips.month` holds a month *name*, and a range over it would compare
     * strings: `'January' >= '2026-07-01'` is a comparison the database will happily answer and nobody can
     * predict. That subject has no period and says so — see `Dataset::periodColumn()`.
     */
    public static function dateRange(string $key, string $label, string $column): self
    {
        return new self($key, $label, self::DATE_RANGE, column: $column);
    }

    /**
     * One value from a list.
     *
     * @param  Closure(): array<string|int, string>  $options
     */
    public static function select(string $key, string $label, string $column, Closure $options): self
    {
        return new self($key, $label, self::SELECT, column: $column, options: $options);
    }

    /**
     * Text, looked for in the columns named.
     *
     * More than one column because that is what somebody means by searching: a reference they half remember
     * is in the number or the memo and they do not know which.
     *
     * @param  array<int, string>  $columns
     */
    public static function search(string $key, string $label, array $columns): self
    {
        if ($columns === []) {
            throw new InvalidArgumentException("Search filter [{$key}] names no columns to search.");
        }

        return new self($key, $label, self::SEARCH, columns: $columns);
    }

    /** Yes or no, over a boolean column. */
    public static function flag(string $key, string $label, string $column): self
    {
        return new self($key, $label, self::FLAG, column: $column);
    }

    /** @return array<string|int, string> */
    public function options(): array
    {
        return $this->options === null ? [] : ($this->options)();
    }

    /**
     * Every column this filter touches, which is what the registry's boundary test checks against the table.
     *
     * @return array<int, string>
     */
    public function touches(): array
    {
        return $this->column === null ? $this->columns : [$this->column];
    }
}
