<?php

namespace App\Filament\CommandPalette\Providers;

use App\Filament\CommandPalette\Concerns\ScoresMatches;
use App\Filament\CommandPalette\PaletteProvider;
use Filament\Facades\Filament;

/**
 * Surfaces every Filament page (Dashboard, report pages, settings, …) the
 * current user may access, via each page's `canAccess()` gate.
 */
class PageProvider implements PaletteProvider
{
    use ScoresMatches;

    public function items(string $query): array
    {
        $items = [];

        foreach (Filament::getPages() as $page) {
            try {
                if (! $page::canAccess()) {
                    continue;
                }

                $url = $page::getUrl();
            } catch (\Throwable) {
                continue;
            }

            $label = (string) $page::getNavigationLabel();
            $group = $page::getNavigationGroup();

            if (($score = $this->score($query, [$label, $group])) !== null) {
                $items[] = [
                    'group' => 'Pages',
                    'label' => $label,
                    'subtitle' => $group,
                    'url' => $url,
                    'icon' => $this->iconHtml($page::getNavigationIcon()),
                    'score' => $score,
                ];
            }
        }

        return $items;
    }
}
