<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\Reporting\ReportComparison;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * One person's saved filters for one report — `docs/reports-expansion-plan.md` Phase 4.5.
 *
 * **Named `SavedReportView` and not `ReportView` on purpose.** `ReportView` is the *permission* every report
 * in this application is gated on, and a model sharing that name would make `can('ReportView')` and
 * `ReportView::find()` read like the same subject when they are unrelated.
 *
 * **The date is not part of the state, which is the phase's own point.** The plan asks for "the filters
 * somebody uses every month", and the date is the one thing that changes every month: a view holding 30 June
 * would open on 30 June for ever and nobody would notice for a while. A fixed date already has a better
 * mechanism — the URL carries the whole state, so "the balance sheet at 30 June" is a link somebody sends.
 * A link for a moment, a saved view for a habit.
 *
 * **Scoped to the signed-in user on every read.** There is no foreign key to `users` — that table is on the
 * landlord connection and a cross-connection constraint is not one — so the scope is what keeps one person's
 * views out of another's list, and it is applied here rather than trusted to each caller.
 */
class SavedReportView extends Model
{
    /**
     * The filters a view may carry, and nothing else.
     *
     * An allow-list rather than "whatever the page had", so a future property on the hub does not silently
     * become part of everybody's saved views — and so `asOf` cannot leak in, which is the one key this must
     * never store.
     *
     * **This is the guard, not the page's omission**, and a mutation proved it: making `Reports::saveView()`
     * pass `asOf` changes nothing, because this list drops it — while adding `asOf` *here* fails immediately.
     * Which is the right way round. The page choosing not to send it is politeness; this is the rule.
     *
     * @var array<int, string>
     */
    public const FILTERS = ['compare', 'account', 'budget', 'find', 'month'];

    protected $fillable = ['user_id', 'report_key', 'name', 'state'];

    protected $casts = ['state' => 'array'];

    /** Views belonging to the signed-in user, oldest first so the list does not reshuffle as they save. */
    public function scopeMine(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id())->orderBy('id');
    }

    /**
     * The signed-in user's views for one report.
     *
     * @return EloquentCollection<int, self>
     */
    public static function forReport(?string $reportKey): EloquentCollection
    {
        // Defensive rather than load-bearing, and no test pretends otherwise: `where('report_key', null)`
        // and `where('user_id', null)` both match nothing, so the query below would return an empty set
        // anyway. This saves the round trip and says what the empty answer means.
        if (blank($reportKey) || auth()->id() === null) {
            return new EloquentCollection;
        }

        return static::query()->mine()->where('report_key', $reportKey)->get();
    }

    /**
     * Save the given filters under a name, replacing a view of that name.
     *
     * Pressing save again after adjusting last month's filters means "this is what that view is now", not
     * "make me a second one called the same thing" — which the unique key enforces and this honours.
     *
     * @param  array<string, mixed>  $state
     */
    public static function put(string $reportKey, string $name, array $state): self
    {
        return static::query()->updateOrCreate(
            ['user_id' => auth()->id(), 'report_key' => $reportKey, 'name' => trim($name)],
            ['state' => static::filtered($state)],
        );
    }

    /**
     * The filters worth storing, from whatever the page handed over.
     *
     * Three rules, and each exists because of what it keeps out:
     *
     *  - **only keys in `FILTERS`**, so `asOf` cannot get in and a new hub property does not join by accident;
     *  - **nothing empty**, because a null account is not a filter, it is the absence of one — and storing it
     *    would make applying the view *clear* a picker somebody had set rather than leave it alone;
     *  - **the comparison basis normalised**, so a saved view cannot preserve a basis the pane would refuse.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function filtered(array $state): array
    {
        $filtered = [];

        foreach (self::FILTERS as $key) {
            $value = $state[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$key] = $key === 'compare'
                ? ReportComparison::normalise((string) $value)
                : $value;
        }

        return $filtered;
    }

    /**
     * The state to apply, with the comparison basis re-checked on the way out as well as in.
     *
     * Checked twice because a row can outlive a basis: a view saved when `previous_fortnight` was briefly a
     * thing would otherwise hand the pane a string it has since stopped recognising.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return static::filtered((array) $this->state);
    }
}
