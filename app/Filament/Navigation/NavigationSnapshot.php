<?php

namespace App\Filament\Navigation;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;

/**
 * The panel's full navigation, assembled once per request.
 *
 * Two things need it now — the rail, which has to see every domain to know which ones have
 * anything in them, and the column, which shows one domain — and assembling it is not cheap:
 * Filament walks 75 resources and 26 pages calling canAccess() on each, which here means a module
 * licence check and a permission check per entry. Rendering the shell used to cost that once;
 * without this it would cost it twice.
 *
 * Registered with `scoped()` rather than `singleton()`, so it is rebuilt per request. A singleton
 * would answer a second request — a different user, a different company, a different licence — out
 * of the first one's cache, which is a cross-tenant leak wearing the clothes of a performance
 * optimisation.
 */
class NavigationSnapshot
{
    /** @var array<NavigationGroup>|null */
    private ?array $groups = null;

    /**
     * Every group, unfiltered — including the domains the current request is not in.
     *
     * @return array<NavigationGroup>
     */
    public function groups(): array
    {
        return $this->groups ??= DomainNavigationManager::withoutFiltering(
            fn (): array => Filament::getCurrentOrDefaultPanel()?->getNavigation() ?? [],
        );
    }
}
