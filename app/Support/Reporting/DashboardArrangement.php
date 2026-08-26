<?php

namespace App\Support\Reporting;

use App\Modules\Core\Models\DashboardLayout;
use App\Support\ModuleMap;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * The dashboard as somebody has arranged it — `docs/reports-expansion-plan.md` Phase 7.
 *
 * `DashboardLayout` is the row; this is the resolution. The division matters because of item 4:
 *
 * > **A layout can never reveal a widget `canView()` refuses.** Resolve the visible set *first*, then order
 * > it. Said explicitly because the tempting implementation — read the layout, instantiate what it names — is
 * > a module-gating bypass that would survive the module being switched off.
 *
 * **That rule is structural here, not a check.** Every method below starts from the list the *panel* handed
 * over and only ever consults the layout as a lookup on it. The stored state is never iterated to produce a
 * widget, so there is no code path in which a stored key could summon one — which is a stronger guarantee
 * than filtering afterwards, because filtering can be forgotten and this cannot be reached.
 *
 * **A widget the layout does not mention appears anyway** (item 1). Unmentioned widgets sort after mentioned
 * ones and among themselves by their own `$sort`, so a chart added after somebody arranged their dashboard
 * turns up at the end in band order rather than not at all. The alternative — storing "the widgets I have" —
 * makes the person who arranged their dashboard the person who never sees a new one.
 *
 * **The grid is six columns wide because three widths need it.** Item 5 asks for half, two thirds and full;
 * two columns cannot express two thirds. Six is the smallest grid that gives all three as whole columns
 * (3, 4, 6) and the change is invisible to anything already built: every existing widget declares either
 * `$columnSpan = 1` — half of the old two-column grid, three of this one — or `'full'`, which is a keyword
 * rather than a number and means the same in any grid.
 */
final class DashboardArrangement
{
    /**
     * How many columns the dashboard's grid has.
     *
     * At the `lg` breakpoint only, which is Filament's default for an integer: below it the grid collapses to
     * one column and every widget is full width, as it was before this phase. Nobody arranges a dashboard for
     * a phone.
     */
    public const COLUMNS = 6;

    public const HALF = 'half';

    public const TWO_THIRDS = 'two_thirds';

    public const FULL = 'full';

    /**
     * The three widths, and why there are three rather than a resize handle.
     *
     * "Three choices need no grid engine and answer the actual complaint, which is that a stats row does not
     * deserve the same space as a twelve-month chart" — item 5. A drag-to-resize would need a stored width per
     * breakpoint and a rule for what happens when the grid narrows; these are picked from a list and cannot be
     * put in a state the grid does not have.
     *
     * @var array<string, string>
     */
    public const WIDTHS = [
        self::HALF => 'Half',
        self::TWO_THIRDS => 'Two thirds',
        self::FULL => 'Full width',
    ];

    /**
     * Each width as a column span.
     *
     * `full` stays the keyword rather than becoming 6: Filament renders it as `1 / -1`, so a widget somebody
     * made full width stays full width if this grid is ever widened, which a stored 6 would not.
     *
     * @var array<string, int|string>
     */
    private const SPANS = [
        self::HALF => 3,
        self::TWO_THIRDS => 4,
        self::FULL => 'full',
    ];

    /**
     * Words that are not words.
     *
     * Labels are derived from class names rather than kept in a table, for the reason `DashboardWidgets` gives
     * about not being a registry of widget names: a list of twenty-three labels is a second place to update
     * when a widget is added, and the one that will be forgotten. Deriving needs exactly two kinds of help —
     * acronyms, which `Str::headline` title-cases into nonsense, and the two names below that come out wrong
     * rather than merely plain.
     *
     * @var array<int, string>
     */
    private const ACRONYMS = ['SLA', 'WIP', 'FBR', 'CRM', 'HR'];

    /**
     * The exceptions, which are corrections and not preferences.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'ReceivablesPayablesOverview' => 'Receivables and payables',
        'OperationsOverview' => 'Company headlines',
    ];

    /**
     * The widgets to render, in order, each carrying its width.
     *
     * @param  array<int, class-string<Widget>|WidgetConfiguration>  $registered
     * @return array<int, WidgetConfiguration>
     */
    public static function widgets(array $registered, ?DashboardLayout $layout): array
    {
        $visible = array_filter(
            self::decorate($registered, $layout),
            fn (array $row): bool => ! $row['hidden'],
        );

        return array_values(array_map(
            fn (array $row): WidgetConfiguration => app(WidgetConfiguration::class, [
                'widget' => $row['class'],
                'properties' => [
                    // Anything the panel already configured survives: a widget registered as
                    // `Widget::make([...])` rather than as a class name keeps its properties and gains a
                    // width, rather than being replaced by a bare configuration of this method's making.
                    ...$row['properties'],
                    'dashboardSpan' => $row['width'],
                ],
            ]),
            $visible,
        ));
    }

    /**
     * The same widgets as rows for the arranger, hidden ones included.
     *
     * Hidden widgets are *shown here and nowhere else*, which is the only way to unhide one: a list that
     * omitted them would be a one-way door.
     *
     * @param  array<int, class-string<Widget>|WidgetConfiguration>  $registered
     * @return array<int, array{alias: string, label: string, module: ?string, width: string, hidden: bool}>
     */
    public static function rows(array $registered, ?DashboardLayout $layout): array
    {
        return array_values(array_map(
            fn (array $row): array => [
                'alias' => $row['alias'],
                'label' => self::labelFor($row['class']),
                'module' => ModuleMap::moduleFor($row['class']),
                'width' => $row['width'],
                'hidden' => $row['hidden'],
            ],
            self::decorate($registered, $layout),
        ));
    }

    /**
     * The panel's widgets, filtered to what may be seen, sorted, and annotated.
     *
     * The one place ordering happens, so the arranger and the dashboard cannot disagree about it — a list you
     * drag in one order and a screen that renders another is the failure this shape rules out.
     *
     * Sorting on `[position, sort]` as a pair rather than comparing positions and falling through: the pair
     * makes the comparison total and independent of the order the panel handed things over in. `getSort()` can
     * never tie, because `DashboardWidgetRulesTest` forbids two widgets sharing a sort.
     *
     * @param  array<int, class-string<Widget>|WidgetConfiguration>  $registered
     * @return array<int, array{class: class-string<Widget>, alias: string, properties: array<string, mixed>, width: string, hidden: bool, sort: int, position: int}>
     */
    private static function decorate(array $registered, ?DashboardLayout $layout): array
    {
        $order = array_flip($layout?->order() ?? []);
        $hidden = $layout?->hidden() ?? [];
        $spans = $layout?->spans() ?? [];

        $rows = [];

        foreach ($registered as $entry) {
            $class = $entry instanceof WidgetConfiguration ? $entry->widget : $entry;

            // Item 4, and the only gate that matters: the panel decides what exists and the widget decides
            // who may see it. Nothing below this line can add to the list.
            if (! $class::canView()) {
                continue;
            }

            $alias = ModuleMap::alias($class);

            $rows[] = [
                'class' => $class,
                'alias' => $alias,
                'properties' => $entry instanceof WidgetConfiguration ? $entry->getProperties() : [],
                'width' => self::isWidth($spans[$alias] ?? null) ? $spans[$alias] : self::defaultWidth($class),
                'hidden' => in_array($alias, $hidden, true),
                'sort' => $class::getSort(),
                'position' => $order[$alias] ?? PHP_INT_MAX,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['position'], $a['sort']] <=> [$b['position'], $b['sort']]);

        return $rows;
    }

    /**
     * The width a widget asks for when nobody has chosen one.
     *
     * Read off the widget's own `$columnSpan` default rather than assumed, so this phase changed no widget's
     * appearance: `'full'` is full, and the `1` that meant "half of two columns" still means half. Read by
     * reflection rather than by instantiating — a widget is a Livewire component and building twenty-three of
     * them to ask each how wide it is would be a page of work to answer a question about a default.
     *
     * **Two thirds is never a default**, deliberately: no widget declares it, because it is the width somebody
     * chooses when a chart needs more room than half and does not deserve the whole row.
     *
     * @param  class-string<Widget>  $class
     */
    public static function defaultWidth(string $class): string
    {
        $span = (new ReflectionProperty($class, 'columnSpan'))->getDefaultValue();

        if ($span === 'full' || (is_int($span) && $span >= 2)) {
            return self::FULL;
        }

        return self::HALF;
    }

    /** A width as a column span the grid understands. */
    public static function span(string $width): int|string
    {
        return self::SPANS[$width] ?? self::SPANS[self::HALF];
    }

    /** Whether something is one of the three offered widths. */
    public static function isWidth(mixed $width): bool
    {
        return is_string($width) && array_key_exists($width, self::WIDTHS);
    }

    /**
     * Every widget alias this application has, dashboard or not.
     *
     * What `DashboardLayout::sanitise()` checks a stored key against. Deliberately the whole set rather than
     * the dashboard's: an alias for a widget that lives on a resource page cannot match anything the dashboard
     * renders, so keeping it costs nothing, while narrowing this to "widgets currently on the dashboard" would
     * quietly delete somebody's arrangement of a widget whose module is switched off this week.
     *
     * @return array<int, string>
     */
    public static function knownAliases(): array
    {
        return array_map(fn (string $class): string => ModuleMap::alias($class), ModuleMap::widgets());
    }

    /**
     * The widget an alias names, or none.
     *
     * The reverse of `ModuleMap::alias()`, and the only place a stored key is turned back into a class — which
     * is why it returns null rather than throwing for something unknown. Item 4 again: this cannot be used to
     * *render* a widget, only to ask a question about one, and its single caller asks "how wide is this widget
     * by default" before storing a width for it.
     *
     * @return class-string<Widget>|null
     */
    public static function classFor(string $alias): ?string
    {
        foreach (ModuleMap::widgets() as $class) {
            if (ModuleMap::alias($class) === $alias) {
                return $class;
            }
        }

        return null;
    }

    /**
     * A widget's name, for the arranger's list.
     *
     * `Str::headline` then lower-casing everything after the first word, which is this application's own
     * house style for a label — "Sales & pipeline", not "Sales & Pipeline". Acronyms are put back in capitals
     * because a widget called `SlaComplianceOverview` is about the SLA and not about somebody named Sla.
     *
     * @param  class-string<Widget>  $class
     */
    public static function labelFor(string $class): string
    {
        $basename = class_basename($class);

        if (array_key_exists($basename, self::LABELS)) {
            return self::LABELS[$basename];
        }

        $words = explode(' ', Str::headline($basename));

        return implode(' ', array_map(
            function (string $word, int $index): string {
                if (in_array(Str::upper($word), self::ACRONYMS, true)) {
                    return Str::upper($word);
                }

                return $index === 0 ? $word : Str::lower($word);
            },
            $words,
            array_keys($words),
        ));
    }
}
