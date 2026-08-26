<?php

namespace App\Support\Reporting;

use Illuminate\Support\HtmlString;

/**
 * One icon per section of the reports hub, defined once and referenced per row.
 *
 * **This exists to keep a page-size ceiling that `PanelPerformanceTest` says must not be raised.** Its
 * comment predicted the moment: "a card costs ~4.5 KB … the remaining plan needs ~60 KB the ceiling does not
 * have, so the hub's card markup is what should give way next, not this number." Fifty-one rows each inlining
 * their own heroicon came to **30.4 KB of a 366 KB page** — measured, not estimated — against a 360 KB
 * budget.
 *
 * **Nine symbols and fifty-one references instead.** `<symbol>` defines each icon once and `<use>` points at
 * it, which takes those 30.4 KB to about 6. That is only a saving because the icons repeat: the reports' own
 * navigation icons were all *distinct*, so a sprite of fifty-one symbols would have saved nothing. Collapsing
 * to one icon per section is what makes the mechanism worth having, and it is a real change to what the
 * screen shows — a row's icon now says which section the report is in rather than being the report's own.
 *
 * The report's own navigation icon is untouched, and still appears on its own page and in the sidebar. What
 * changed is only the explorer row, where fifty-one arbitrary heroicons distinguished nothing a reader was
 * using: they identify a report by its name, and the icon column reads as a texture.
 */
class ReportIcons
{
    /**
     * Section heading to heroicon.
     *
     * Keyed on the heading itself rather than on a slug, because the heading is what a module writes when it
     * calls `ReportCatalogue::register()` — there is no other identifier, and inventing one would mean two
     * places to keep in step.
     *
     * @var array<string, string>
     */
    public const SECTION_ICONS = [
        'Financial statements' => 'heroicon-o-chart-pie',
        'Receivables & payables' => 'heroicon-o-banknotes',
        'Payroll & tax' => 'heroicon-o-user-group',
        'Statutory reporting' => 'heroicon-o-document-check',
        'Ledgers & books' => 'heroicon-o-book-open',
        'Bank files' => 'heroicon-o-building-library',
        'Sales & pipeline' => 'heroicon-o-presentation-chart-line',
        'People & payroll' => 'heroicon-o-users',
        'Operations' => 'heroicon-o-cog-6-tooth',

        // The reports somebody assembled — Phase 6, item 4. Mapped rather than left to the fallback because
        // this is the one section whose rows are *not* the same for two people, and the icon is what says so
        // in a list where every other row is a report the whole company has.
        ReportCatalogue::CUSTOM => 'heroicon-o-squares-plus',
    ];

    /**
     * For a section nobody mapped.
     *
     * A new module registering a new heading gets a generic report icon rather than nothing — an empty icon
     * slot would look like a rendering failure, and a missing key here is a small omission rather than a bug
     * worth breaking the page over.
     */
    public const FALLBACK = 'heroicon-o-document-chart-bar';

    /** The sprite id a section's rows point at. */
    public static function idFor(string $section): string
    {
        return 'rpt-icon-'.str($section)->slug()->value();
    }

    /**
     * The sprite: every mapped section's icon as a `<symbol>`, once.
     *
     * Every section rather than only the visible ones, because the list is filtered by section and by search
     * and re-renders on both — a sprite that shrank with the filter would drop the symbol a row still points
     * at, and the row would render blank. Nine symbols is under 4 KB and does not move.
     */
    public static function sprite(): HtmlString
    {
        $symbols = '';

        foreach (self::SECTION_ICONS as $section => $icon) {
            $symbols .= self::symbol(self::idFor($section), $icon);
        }

        $symbols .= self::symbol(self::idFor('__fallback'), self::FALLBACK);

        // `position: absolute` and no size: a sprite is a definition, not a picture. `aria-hidden` because a
        // screen reader has nothing to say about a bag of shapes.
        return new HtmlString(
            '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" '
            .'style="position:absolute;width:0;height:0;overflow:hidden">'.$symbols.'</svg>'
        );
    }

    /**
     * One heroicon as a `<symbol>`.
     *
     * The icon's own presentation attributes are carried onto the symbol — `fill`, `stroke`,
     * `stroke-width`, `viewBox` — because a `<use>` inherits them from the symbol and a heroicon outline
     * drawn without `stroke="currentColor"` is an invisible icon rather than a wrong one. `xmlns` and any
     * `class`, `id`, `width` or `height` are dropped: the first is on the sprite's own root and the rest
     * belong to the referencing element.
     */
    private static function symbol(string $id, string $icon): string
    {
        $rendered = svg($icon)->toHtml();

        if (! preg_match('/<svg\b([^>]*)>(.*)<\/svg>/s', $rendered, $matches)) {
            return '';
        }

        $attributes = preg_replace(
            '/\s(?:xmlns(?::\w+)?|class|id|width|height|aria-hidden|data-[\w-]+)="[^"]*"/i',
            '',
            $matches[1],
        );

        return '<symbol id="'.e($id).'"'.$attributes.'>'.$matches[2].'</symbol>';
    }

    /**
     * The `<use>` element for one section's row.
     *
     * `aria-hidden`, because the row's own text names the report — an icon shared by thirteen rows announces
     * nothing a screen reader's user wants thirteen times.
     */
    public static function icon(string $section, string $class = 'fi-explorer-row-icon-svg'): HtmlString
    {
        $id = array_key_exists($section, self::SECTION_ICONS)
            ? self::idFor($section)
            : self::idFor('__fallback');

        return new HtmlString(
            '<svg class="'.e($class).'" aria-hidden="true" focusable="false">'
            .'<use href="#'.e($id).'"></use></svg>'
        );
    }
}
