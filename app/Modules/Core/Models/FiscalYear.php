<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

class FiscalYear extends Model
{
    use Auditable;

    protected $fillable = ['name', 'start_date', 'end_date', 'is_active', 'closed_at', 'closed_by'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'closed_at' => 'datetime',
    ];

    /**
     * One active year at a time, enforced.
     *
     * Everything that asks for the current year asks the same way — `where
     * is_active, first()` — so a second active year does not read as an error
     * anywhere. It reads as the wrong year: whichever has the lower id wins, and
     * on the company this was found on that was a year containing not one of its
     * entries. Activating a year now stands the others down.
     */
    /**
     * The shape a fiscal year is allowed to have.
     *
     * This installation runs a **1 July – 30 June** year, and `App\Support\PayrollMonth` is what depends on
     * it: payslips are keyed by month *name*, so turning "January" into a date needs the year's boundary. Two
     * rules are enough to pin that down without forbidding the one legitimate variation:
     *
     *  - **A year ends 30 June.** Always, including a partial one.
     *  - **A year spans at most twelve months.** Longer and a month name appears twice in the same year, so
     *    "January" is genuinely ambiguous and `PayrollMonth` would silently pick one of them.
     *
     * Together those permit exactly one full-length shape — 1 July to 30 June — without needing to say so,
     * and they still allow a **stub first year**: a company joining in February gets 1 February to 30 June,
     * which `BudgetTest` covers and which exists for a real reason (inventing the eight missing months would
     * report a year as underspent against actuals that were never going to be there).
     *
     * **Validated only when both dates are recorded**, and that is not laziness. `FiscalYearForm` does not
     * collect dates, so rows created through the panel have none, and `containing()` already documents years
     * with no dates as containing nothing. Refusing to save a dateless row would lock an administrator out of
     * activating one. See the note in docs/module-packaging-plan.md — a dateless year makes `PayrollMonth`
     * resolve every month to the current calendar year, which is a separate bug and not one validation here
     * can fix without breaking the panel.
     */
    protected static function booted(): void
    {
        static::saving(function (self $year): void {
            if ($year->start_date === null || $year->end_date === null) {
                return;
            }

            $start = \Carbon\Carbon::parse($year->start_date)->startOfDay();
            $end = \Carbon\Carbon::parse($year->end_date)->startOfDay();

            if ($end->lessThanOrEqualTo($start)) {
                throw new \InvalidArgumentException(sprintf(
                    'A fiscal year must end after it starts — got %s to %s.',
                    $start->toDateString(),
                    $end->toDateString(),
                ));
            }

            if ($end->month !== 6 || $end->day !== 30) {
                throw new \InvalidArgumentException(sprintf(
                    'A fiscal year ends on 30 June — got %s. A company joining part-way through gets a '
                    .'shorter year ending on the same date, not a year ending elsewhere.',
                    $end->toDateString(),
                ));
            }

            // Compared against the anniversary rather than by counting months, which is off by one for a
            // 1 July – 30 June year and would reject the ordinary case.
            if ($end->greaterThanOrEqualTo($start->copy()->addYear())) {
                throw new \InvalidArgumentException(sprintf(
                    'A fiscal year spans at most twelve months — %s to %s is longer, so a month name would '
                    .'fall in it twice and could not be resolved to a date.',
                    $start->toDateString(),
                    $end->toDateString(),
                ));
            }
        });

        static::saved(function (self $year): void {
            if (! $year->is_active) {
                return;
            }

            // Asserted, not assumed. A model loaded while it was active, and stood
            // down in the database since by another year being activated, is not
            // dirty when it is activated again — Eloquent writes nothing, and the
            // row stays false while the next line stands every other year down.
            // That leaves no active year at all.
            static::whereKey($year->getKey())->update(['is_active' => true]);

            static::whereKeyNot($year->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);
        });
    }

    /**
     * The one active year, or none.
     *
     * Worth going through rather than repeating the query: it is the single place
     * that decides what "current" means.
     */
    public static function current(): ?self
    {
        return static::where('is_active', true)->orderByDesc('start_date')->first();
    }

    /** A closed year's ledger is frozen: nothing may post into it. */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('closed_at');
    }

    /**
     * The year whose date range contains $date, if any.
     *
     * Used to decide whether a journal entry falls inside a closed period.
     * Years with no dates recorded cannot contain anything, so they are skipped.
     */
    public static function containing(string $date): ?self
    {
        return static::whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }
}
