<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * Store a date column as a date, not as midnight of one.
 *
 * Eloquent's `date` cast writes through `fromDateTime()`, which formats with the
 * connection's `Y-m-d H:i:s`. MySQL truncates that to fit a DATE column; SQLite is
 * typeless and keeps the whole string. So the test database holds
 * `2026-01-01 00:00:00` where production holds `2026-01-01`, and every bare string
 * comparison — `where('leave_year_start', '<=', $date)`, `whereBetween('date', …)`,
 * and the `firstOrNew` lookups the entitlement service relies on — is true on one and
 * false on the other.
 *
 * That is not hypothetical here: it is what made three leave tests die on a unique
 * constraint. `firstOrNew(['leave_year_start' => '2026-01-01'])` matched nothing
 * against a stored `2026-01-01 00:00:00`, so an idempotent open tried to insert a
 * second row for a year that already had one — and it would have failed in production
 * only if production were SQLite, which is worse than failing everywhere.
 *
 * A set mutator runs before the cast's date handling, which is what makes the two
 * agree. It also keeps reads as bare comparisons against the real indexes rather than
 * `whereDate()`, which wraps the column in a function and stops MySQL using them.
 *
 * The precedent, and the same reasoning at more length, is
 * EmployeeJobHistory::setEffectiveFromAttribute(). This trait exists because the leave
 * tables have five such columns across three models and one hand-written mutator each
 * is five chances to forget.
 *
 * Usage: list the columns in `$plainDates` on the model.
 */
trait StoresPlainDates
{
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->plainDates ?? [], true)) {
            $this->attributes[$key] = ($value === null || $value === '')
                ? null
                : Carbon::parse($value)->toDateString();

            return $this;
        }

        return parent::setAttribute($key, $value);
    }
}
