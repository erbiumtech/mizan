<?php

namespace App\Filament\CommandPalette\Providers;

use App\Filament\CommandPalette\Concerns\ScoresMatches;
use App\Filament\CommandPalette\PaletteProvider;
use Filament\Facades\Filament;

/**
 * Quick commands: switch to another of the user's companies, log out, toggle
 * theme. Navigational commands (dashboard, settings) already come from the Page
 * provider. Client-side actions carry a `command` key handled in the Blade view.
 */
class CommandProvider implements PaletteProvider
{
    use ScoresMatches;

    public function items(string $query): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $items = [];
        $panel = Filament::getCurrentPanel();
        $current = Filament::getTenant();

        // Switch company — one item per other company the user belongs to.
        if ($panel && method_exists($user, 'getTenants')) {
            foreach ($user->getTenants($panel) as $company) {
                if ($current && $company->getKey() === $current->getKey()) {
                    continue;
                }

                $label = 'Switch to '.$company->name;

                if (($score = $this->score($query, [$label, $company->name, 'switch company'])) !== null) {
                    $items[] = [
                        'group' => 'Commands',
                        'label' => $label,
                        'subtitle' => 'Company',
                        'url' => $panel->getUrl($company),
                        'icon' => $this->iconHtml('heroicon-o-building-office-2'),
                        'score' => $score,
                    ];
                }
            }
        }

        // Toggle light/dark theme (client-side).
        if (($score = $this->score($query, ['Toggle theme', 'dark mode', 'light mode'])) !== null) {
            $items[] = [
                'group' => 'Commands',
                'label' => 'Toggle theme',
                'subtitle' => 'Appearance',
                'command' => 'toggle-theme',
                'icon' => $this->iconHtml('heroicon-o-swatch'),
                'score' => $score,
            ];
        }

        // Log out (client-side POST).
        if (($score = $this->score($query, ['Log out', 'logout', 'sign out'])) !== null) {
            $items[] = [
                'group' => 'Commands',
                'label' => 'Log out',
                'subtitle' => null,
                'command' => 'logout',
                'icon' => $this->iconHtml('heroicon-o-arrow-left-on-rectangle'),
                'score' => $score,
            ];
        }

        return $items;
    }
}
