<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

/**
 * The "Help" header action every resource list and standalone page carries: a
 * right-hand slide-over rendering the matching markdown file under
 * resources/markdown/help/. One place to keep the behaviour (slide-over from
 * the end/right, no submit button, "Close" only) consistent across every help
 * topic in the app rather than repeating it per page.
 *
 * No visibility check of its own: reaching the page this is attached to
 * already required passing that page's own canAccess(), and help content
 * carries nothing sensitive on top of what the page already shows.
 */
final class HelpAction
{
    public static function make(string $slug, string $heading): Action
    {
        return Action::make('help')
            ->label('Help')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->slideOver()
            ->modalHeading($heading)
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('filament.help.content', [
                'markdown' => static::markdown($slug),
            ]));
    }

    /**
     * Rendered once per request rather than cached: this is help content, not
     * a hot path, and a cache would be one more thing to invalidate when it
     * changes.
     */
    public static function markdown(string $slug): string
    {
        return Str::markdown(
            file_get_contents(resource_path("markdown/help/{$slug}.md"))
        );
    }
}
