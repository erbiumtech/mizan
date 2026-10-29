<?php

namespace App\Support;

use BackedEnum;
use Filament\Support\Enums\IconSize;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

use function Filament\Support\generate_icon_html;

/**
 * The same icon, sent once — docs/page-load-performance-plan.md.
 *
 * The shell's flyout repeats one `<li>` per screen of every licensed module, on
 * every page, and each one carried a complete inline `<svg>`. Heroicons are around
 * 400 bytes of path data each and the set the rail draws from is small, so most of
 * those bytes were the same twenty icons sent over and over: measured at 115 KB of
 * inline SVG on the employees page, 44 KB of it repeats.
 *
 * This collects each distinct icon once as a `<symbol>` and leaves a `<use>` where
 * it was referenced. The sprite is emitted at the end of the rail; `<use>` resolves
 * against the whole document after parsing, so the order does not matter.
 *
 * **One instance per rendering, held by the view that uses it.** The symbols belong
 * to the page being drawn; a shared instance would emit a sprite carrying the last
 * page's icons and missing this one's. There is deliberately no container binding —
 * the rail resolves one and keeps it in a local, which is the whole lifetime needed.
 *
 * Anything that is not an inline SVG — an `<img>`, a font icon, a view — is passed
 * through untouched. There is nothing to collapse and guessing at its markup is how
 * this would break somebody's custom icon set.
 */
class IconSprite
{
    /** @var array<string, string> element id => the `<symbol>` for it */
    private array $symbols = [];

    /** One icon, as a reference to the sprite. */
    public function use(string|BackedEnum|null $icon, IconSize $size = IconSize::Small): HtmlString
    {
        if ($icon === null || $icon === '') {
            return new HtmlString('');
        }

        $html = generate_icon_html($icon, size: $size);
        $html = is_object($html) && method_exists($html, 'toHtml') ? $html->toHtml() : (string) $html;

        if (! preg_match('/^<svg([^>]*)>(.*)<\/svg>\s*$/s', $html, $svg)) {
            return new HtmlString($html);
        }

        $name = $icon instanceof BackedEnum ? (string) $icon->value : $icon;
        $id = 'ic-'.Str::slug($name.'-'.$size->value);

        if (! isset($this->symbols[$id])) {
            // Everything but the class moves to the symbol: `fill`, `stroke` and
            // `stroke-width` are what make an outline heroicon an outline, and they are
            // inherited by the content a `<use>` clones.
            $attributes = preg_replace('/\s*class="[^"]*"/', '', $svg[1]);
            $this->symbols[$id] = '<symbol id="'.$id.'"'.$attributes.'>'.$svg[2].'</symbol>';
        }

        preg_match('/class="([^"]*)"/', $svg[1], $class);

        return new HtmlString('<svg class="'.($class[1] ?? '').'" aria-hidden="true"><use href="#'.$id.'"/></svg>');
    }

    /** Every icon referenced so far, once, in one hidden block. */
    public function sprite(): HtmlString
    {
        if ($this->symbols === []) {
            return new HtmlString('');
        }

        // `display:none` rather than the `hidden` attribute: a hidden `<svg>` still has
        // to be in the render tree for its symbols to be referenced in some browsers,
        // and zero size with no display is the combination that works everywhere.
        return new HtmlString(
            '<svg style="display:none" aria-hidden="true" focusable="false">'.implode('', $this->symbols).'</svg>'
        );
    }
}
