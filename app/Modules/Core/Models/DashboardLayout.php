<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\Reporting\DashboardArrangement;
use Illuminate\Database\Eloquent\Builder;

/**
 * How the dashboard is arranged — `docs/reports-expansion-plan.md` Phase 7.
 *
 * **A layout is a partial override, never a list of widgets** (item 1). What is stored is an *order*, a
 * *hidden* set and a *spans* map; what is rendered is "the widgets this person may see, with those applied".
 * The consequence is the point: a widget the layout has never heard of appears anyway, so somebody who
 * arranged their dashboard in March still sees the chart added in June. Store "the widgets I have" instead and
 * that person never sees a new one again.
 *
 * **A company default plus a personal override** (item 2). `user_id` null is the arrangement everybody starts
 * from; a row with a user id is that person departing from it, and deleting their row is the reset. This keeps
 * what is valuable about one shared dashboard — a company where nobody can say "look at the third chart" has
 * lost something — while letting somebody who lives in one module put it first.
 *
 * **Keyed on `ModuleMap::alias()`, not on the class name**, for the reason `TableView::setResourceAttribute()`
 * does it: a widget that moves between directories must not orphan every saved layout. Phase 5 moved
 * `OperationsOverview` and this application has moved every class it owns at least once.
 *
 * **Nothing here decides what somebody may see.** That is `DashboardArrangement`'s job and it starts from the
 * panel's own list — see item 4. This model holds preferences about widgets; it is never the source of which
 * widgets exist, because a stored key that could summon a widget would be a module gate with a hole in it.
 */
class DashboardLayout extends Model
{
    /**
     * The three things a layout may say, and nothing else.
     *
     * An allow-list for the same reason `SavedReportView::FILTERS` is one: the page hands over a state and
     * this decides what of it is storable, so a future property on the dashboard cannot silently join
     * everybody's saved layout.
     *
     * @var array<int, string>
     */
    public const KEYS = ['order', 'hidden', 'spans'];

    protected $fillable = ['user_id', 'state'];

    protected $casts = ['state' => 'array'];

    /**
     * The layout in force for the signed-in user: their own if they have one, otherwise the company default.
     *
     * **One query for both rows**, which is deliberate. The dashboard is the page `PanelPerformanceTest`
     * watches most closely, and "read mine, then read the default if that was empty" is two round trips to
     * answer one question on every render. Fetching both and choosing in PHP costs one query and at most two
     * small rows.
     *
     * With nobody signed in — a console command, a queued job — this is the company default, which is the
     * honest answer: there is no personal layout because there is no person.
     */
    public static function inForce(): ?self
    {
        $userId = auth()->id();

        $rows = static::query()
            ->where(function (Builder $query) use ($userId): void {
                $query->whereNull('user_id');

                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->get();

        return $rows->first(fn (self $row): bool => $row->user_id !== null)
            ?? $rows->first(fn (self $row): bool => $row->user_id === null);
    }

    /** Strictly the signed-in user's own row, or none. */
    public static function mine(): ?self
    {
        $userId = auth()->id();

        return $userId === null ? null : static::query()->where('user_id', $userId)->first();
    }

    /** Strictly the company default, or none. */
    public static function companyDefault(): ?self
    {
        return static::query()->whereNull('user_id')->first();
    }

    /**
     * Save a state for one user, or for the company when `$userId` is null.
     *
     * `updateOrCreate` rather than a second row: one layout per person is the whole model, and Eloquent
     * turns `['user_id' => null]` into `whereNull`, which is what makes the company default a single row
     * despite SQL's unique indexes treating NULLs as distinct.
     *
     * @param  array<string, mixed>  $state
     */
    public static function put(?int $userId, array $state): self
    {
        return static::query()->updateOrCreate(
            ['user_id' => $userId],
            ['state' => static::sanitise($state)],
        );
    }

    /**
     * Drop the signed-in user's layout, so the company default applies again.
     *
     * Item 2's "reset back to it": deleting the override rather than copying the default into it, so somebody
     * who resets today still follows the default if an administrator changes it tomorrow.
     */
    public static function forgetMine(): void
    {
        if ($userId = auth()->id()) {
            static::query()->where('user_id', $userId)->delete();
        }
    }

    /**
     * Drop every personal layout, leaving only the company default.
     *
     * Item 7's administrator action, and the one the plan says must ask first: this discards arrangements
     * people made for themselves. It leaves the default row alone — the point is that everybody sees it.
     */
    public static function forgetEveryones(): int
    {
        return static::query()->whereNotNull('user_id')->delete();
    }

    /** @return array<int, string> */
    public function order(): array
    {
        return static::sanitise((array) $this->state)['order'];
    }

    /** @return array<int, string> */
    public function hidden(): array
    {
        return static::sanitise((array) $this->state)['hidden'];
    }

    /** @return array<string, string> */
    public function spans(): array
    {
        return static::sanitise((array) $this->state)['spans'];
    }

    /**
     * A state with only what a layout may hold, sanitised on the way in *and* on the way out.
     *
     * Both directions, because a row can outlive the code that wrote it — `SavedReportView` takes the same
     * position for the same reason. A layout naming a widget that has since been deleted would otherwise keep
     * a position in the order for something that cannot render, and a width nothing recognises would reach the
     * grid as a CSS value.
     *
     * Three rules:
     *
     *  - **only keys in `KEYS`**, so a new dashboard property does not join by accident;
     *  - **only aliases of widgets that exist**, which is "unknown keys are dropped on read" from item 4.
     *    Note what this deliberately does *not* drop: an alias whose module is currently switched off. That
     *    widget still exists, and erasing somebody's arrangement of it because their company paused a module
     *    for a fortnight would be the feature quietly forgetting things;
     *  - **only widths from the offered set**, and a width for a widget the order does not mention is still
     *    kept, because widths and order are independent choices.
     *
     * @param  array<string, mixed>  $state
     * @return array{order: array<int, string>, hidden: array<int, string>, spans: array<string, string>}
     */
    public static function sanitise(array $state): array
    {
        $known = DashboardArrangement::knownAliases();

        $aliases = function (mixed $value) use ($known): array {
            if (! is_array($value)) {
                return [];
            }

            return array_values(array_unique(array_filter(
                $value,
                fn (mixed $alias): bool => is_string($alias) && in_array($alias, $known, true),
            )));
        };

        $spans = [];

        foreach ((array) ($state['spans'] ?? []) as $alias => $width) {
            if (in_array($alias, $known, true) && DashboardArrangement::isWidth($width)) {
                $spans[$alias] = $width;
            }
        }

        return [
            'order' => $aliases($state['order'] ?? []),
            'hidden' => $aliases($state['hidden'] ?? []),
            'spans' => $spans,
        ];
    }
}
