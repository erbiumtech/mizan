<?php

namespace App\Filament\CommandPalette\Providers;

use App\Filament\CommandPalette\Concerns\ScoresMatches;
use App\Filament\CommandPalette\PaletteProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Surfaces individual records via each resource's Filament global search
 * (`getGlobalSearchResults`). This runs on the tenant connection and is gated by
 * `canGloballySearch()` (policy + non-empty searchable attributes), so results
 * are scoped to the current company only — no cross-tenant leakage.
 */
class RecordProvider implements PaletteProvider
{
    use ScoresMatches;

    /** Records only kick in once the query is meaningful (avoids per-keystroke DB hits). */
    protected const MIN_QUERY_LENGTH = 2;

    public function items(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $items = [];

        foreach (Filament::getResources() as $resource) {
            try {
                if (! $resource::canGloballySearch()) {
                    continue;
                }

                $results = $resource::getGlobalSearchResults($query);
            } catch (\Throwable) {
                continue;
            }

            $category = (string) $resource::getNavigationLabel();
            $icon = $this->iconHtml($resource::getNavigationIcon());

            foreach ($results as $result) {
                $title = $result->title instanceof Htmlable
                    ? strip_tags($result->title->toHtml())
                    : (string) $result->title;

                $items[] = [
                    'group' => 'Records',
                    'label' => $title,
                    'subtitle' => $category,
                    'url' => $result->url,
                    'icon' => $icon,
                    'score' => $this->score($query, [$title]) ?? 100,
                ];
            }
        }

        return $items;
    }
}
