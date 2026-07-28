<?php

namespace App\Filament\CommandPalette\Providers;

use App\Filament\CommandPalette\Concerns\ScoresMatches;
use App\Filament\CommandPalette\PaletteProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Surfaces every Filament resource the current user may access in the current
 * company — a "list" entry and, where permitted, a "New …" entry. Authorization
 * runs through the resource's own gates (policies scoped to the current team).
 */
class ResourceProvider implements PaletteProvider
{
    use ScoresMatches;

    public function items(string $query): array
    {
        $items = [];

        foreach (Filament::getResources() as $resource) {
            try {
                // `canAccess()`, not `canViewAny()`: a resource may restrict
                // itself beyond its policy (CompanyResource is super-admin only,
                // for instance), and that is the same gate the sidebar and the
                // pages themselves use. Filament's default `canAccess()` falls
                // through to `canViewAny()`, so this is never weaker.
                if (! $resource::canAccess()) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $label = (string) $resource::getNavigationLabel();
            $group = $resource::getNavigationGroup();
            $icon = $this->iconHtml($resource::getNavigationIcon());

            if (($url = $this->url($resource, 'index')) !== null
                && ($score = $this->score($query, [$label, $group])) !== null) {
                $items[] = [
                    'group' => 'Resources',
                    'label' => $label,
                    'subtitle' => $group,
                    'url' => $url,
                    'icon' => $icon,
                    'score' => $score,
                ];
            }

            if ($this->canCreate($resource)
                && ($createUrl = $this->url($resource, 'create')) !== null) {
                $createLabel = 'New '.Str::singular($label);

                if (($score = $this->score($query, [$createLabel, $label])) !== null) {
                    $items[] = [
                        'group' => 'Resources',
                        'label' => $createLabel,
                        'subtitle' => $group,
                        'url' => $createUrl,
                        'icon' => $this->iconHtml('heroicon-o-plus-circle'),
                        'score' => $score - 5, // rank just below the list entry
                    ];
                }
            }
        }

        return $items;
    }

    protected function canCreate(string $resource): bool
    {
        try {
            return array_key_exists('create', $resource::getPages()) && $resource::canCreate();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function url(string $resource, string $page): ?string
    {
        try {
            return $resource::getUrl($page);
        } catch (\Throwable) {
            return null;
        }
    }
}
