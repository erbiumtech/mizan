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
 * No visibility check of its own: reaching the page this is attached to already
 * required passing that page's own canAccess(), and help content carries
 * nothing sensitive on top of what the page already shows.
 *
 * The content is tailored to whoever opened it, in two ways:
 *
 *  - a banner naming what their role may and may not do here, worked out from
 *    the resource's policy (see HelpAccess);
 *  - any "Roles at a glance" table is cut down to the reader's own row.
 *
 * The prose is deliberately NOT filtered. An Accountant who cannot approve
 * still needs the Approval section to know who the entry goes to and what will
 * happen to it — the complaint that prompted this was that the panel never said
 * which part was theirs, not that it said too much.
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
            ->modalContent(fn ($livewire) => view('filament.help.content', [
                'markdown' => static::markdown($slug),
                'access' => static::accessFor($livewire),
            ]));
    }

    /**
     * Rendered once per request rather than cached: this is help content, not
     * a hot path, and a cache would be one more thing to invalidate when it
     * changes.
     */
    public static function markdown(string $slug): string
    {
        $source = file_get_contents(resource_path("markdown/help/{$slug}.md"));

        return Str::markdown(static::trimRoleTable($source));
    }

    /**
     * @return array{role: string|null, can: array<int, string>, cannot: array<int, array{verb: string, who: array<int, string>}>}|null
     */
    public static function accessFor(mixed $livewire): ?array
    {
        $model = static::modelBehind($livewire);

        if ($model === null) {
            return null;
        }

        $summary = HelpAccess::summarise(auth()->user(), $model);

        // Nothing granular to say — a policy that gates on role alone, or a
        // reader with no role at all. Better no banner than an empty one.
        return ($summary['can'] === [] && $summary['cannot'] === []) ? null : $summary;
    }

    /**
     * The model whose policy describes this screen, or null for the standalone
     * pages (reports, importers) that have no single record type behind them.
     *
     * Taken from the Livewire component the action is mounted on rather than
     * passed in at every call site: a resource's list page already knows its
     * own resource, and threading it through sixty make() calls by hand is
     * sixty chances to point a screen at the wrong policy.
     */
    private static function modelBehind(mixed $livewire): ?string
    {
        if (! is_object($livewire) || ! method_exists($livewire, 'getResource')) {
            return null;
        }

        try {
            $model = $livewire::getResource()::getModel();
        } catch (\Throwable) {
            return null;
        }

        return (is_string($model) && class_exists($model)) ? $model : null;
    }

    /**
     * Cut a "| Role | … |" table down to the reader's own row.
     *
     * Keyed on the first column header being exactly "Role", which is the only
     * table shape in the help docs whose rows are roles; the far more common
     * "| Permission | What it allows |" tables are left alone. If nothing
     * matches — an unusual role, or a super admin who holds none — the whole
     * table stays, because a table with a header and no rows tells the reader
     * less than the full one did.
     */
    private static function trimRoleTable(string $markdown): string
    {
        $user = auth()->user();

        if ($user === null || ! str_contains($markdown, '| Role |')) {
            return $markdown;
        }

        $mine = $user->roles->pluck('name')->all();

        if ($mine === []) {
            return $markdown;
        }

        $lines = explode("\n", $markdown);
        $out = [];
        $inRoleTable = false;
        $kept = 0;
        $rowsFrom = null;

        foreach ($lines as $line) {
            $isRow = str_starts_with(ltrim($line), '|');

            if ($isRow && preg_match('/^\|\s*Role\s*\|/', ltrim($line))) {
                $inRoleTable = true;
                $kept = 0;
                $rowsFrom = count($out) + 2; // header + separator
                $out[] = $line;

                continue;
            }

            if (! $inRoleTable) {
                $out[] = $line;

                continue;
            }

            if (! $isRow) {
                // Table finished. Put it back whole if the reader matched none
                // of its rows.
                if ($kept === 0 && $rowsFrom !== null) {
                    return $markdown;
                }

                $inRoleTable = false;
                $rowsFrom = null;
                $out[] = $line;

                continue;
            }

            // The separator row directly under the header.
            if (preg_match('/^\|[\s\-:|]+\|$/', trim($line))) {
                $out[] = $line;

                continue;
            }

            $firstCell = trim(explode('|', trim($line, "| \t"))[0] ?? '');

            // Rows name one or several roles: "Manager / CEO".
            $namesMe = collect(preg_split('#\s*/\s*#', $firstCell))
                ->map(fn (string $name) => trim($name))
                ->intersect($mine)
                ->isNotEmpty();

            if ($namesMe) {
                $kept++;
                $out[] = $line;
            }
        }

        if ($inRoleTable && $kept === 0) {
            return $markdown;
        }

        return implode("\n", $out);
    }
}
