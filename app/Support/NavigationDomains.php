<?php

namespace App\Support;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Filament\Pages\UserManual;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use UnitEnum;

/**
 * The first level of the two-level navigation: which domain a screen belongs to.
 *
 * The sidebar had thirteen groups in it, all at one level, and the only way to find out what was
 * in a group was to open it. This splits that into a rail of six domains and a column showing one
 * domain at a time — the arrangement in the 3a design, whose grouping this follows.
 *
 * **Filtering navigation makes unmapped things unreachable**, which is the whole risk of this
 * class. A group missing from the map below belongs to no domain, so no domain's column shows it,
 * so nothing in it can be reached except by URL or the ⌘K palette. NavigationDomainsTest asserts
 * total coverage in both directions rather than trusting that whoever adds a group reads this
 * comment.
 */
class NavigationDomains
{
    /**
     * The panel this applies to.
     *
     * Filament resolves one NavigationManager for every panel, so the binding that narrows the
     * sidebar is global whether or not this is. The platform panel must be left alone: its groups
     * (Audit, and the installation-level resources beside it) appear in no domain below, so
     * filtering it would leave a super admin looking at an empty sidebar on the one panel that has
     * no other way in.
     */
    public const string PANEL = 'admin';

    /**
     * The rail, top to bottom.
     *
     * `groups` are navigation group labels as the resources and pages declare them. `items` are
     * for pages that register no group at all — Filament collects those into one unlabelled group
     * and they are claimed here individually, because Dashboard and User Manual belong to Home
     * while the Reports hub is a domain of its own.
     *
     * Two mappings the design does not settle, decided here:
     *
     *  - **Sales is its own domain**, as in 3a's rail. The 2c frame nests it under Finance. 3a is
     *    what was chosen, and a company that sells is in that domain all day.
     *  - **Personal (the employee's own tax estimate) sits in Home**, which the design never
     *    mentions. It is the one screen here that is about the person looking at it rather than
     *    about the company, which puts it beside the Dashboard rather than in People — People is
     *    where you go to act on *somebody else's* record.
     *
     * @var array<string, array{label: string, icon: string, groups: array<int, string>, items: array<int, class-string>}>
     */
    private const DOMAINS = [
        'home' => [
            'label' => 'Home',
            'icon' => 'heroicon-o-home',
            'groups' => ['Personal'],
            'items' => [Dashboard::class, UserManual::class],
        ],
        'reports' => [
            'label' => 'Reports',
            'icon' => 'heroicon-o-chart-pie',
            // The hub is the only entry that appears, and it appears ungrouped — hence the item
            // below. The group is claimed as well because every report page still *declares*
            // `$navigationGroup = 'Reports'` while hiding itself from the sidebar, and that
            // declaration is what puts an open balance sheet in this domain rather than in Home.
            'groups' => ['Reports'],
            'items' => [Reports::class],
        ],
        'finance' => [
            'label' => 'Finance',
            'icon' => 'heroicon-o-banknotes',
            'groups' => ['Accounting', 'Invoicing & Inventory', 'Audit & Taxes'],
            'items' => [],
        ],
        'people' => [
            'label' => 'People',
            'icon' => 'heroicon-o-users',
            'groups' => ['Employee', 'Hiring', 'Performance'],
            'items' => [],
        ],
        'sales' => [
            'label' => 'Sales',
            'icon' => 'heroicon-o-presentation-chart-line',
            'groups' => ['Sales'],
            'items' => [],
        ],
        'admin' => [
            'label' => 'Admin',
            'icon' => 'heroicon-o-cog-6-tooth',
            'groups' => ['Settings', 'Access Control', 'Support'],
            'items' => [],
        ],
    ];

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::DOMAINS);
    }

    /** @return array{label: string, icon: string, groups: array<int, string>, items: array<int, class-string>} */
    public static function definition(string $key): array
    {
        return self::DOMAINS[$key];
    }

    /** Every group label claimed by any domain. @return array<int, string> */
    public static function mappedGroups(): array
    {
        return array_merge(...array_column(self::DOMAINS, 'groups'));
    }

    /** Every ungrouped page claimed by any domain. @return array<int, class-string> */
    public static function mappedItems(): array
    {
        return array_merge(...array_column(self::DOMAINS, 'items'));
    }

    /** Which domain owns a navigation group label, or null if the map has a hole in it. */
    public static function forGroup(string|UnitEnum|null $group): ?string
    {
        $label = self::label($group);

        if ($label === null) {
            return null;
        }

        foreach (self::DOMAINS as $key => $domain) {
            if (in_array($label, $domain['groups'], true)) {
                return $key;
            }

            // Also by branch label, so this answers for either name. Pages resolve their domain
            // through here from the group they *declare* ("Employee"), while anything reading a
            // rendered group sees the branch it was split into ("Payroll") — both have to land in
            // the same domain or the rail and the column disagree about where you are.
            foreach ($domain['groups'] as $group) {
                if (in_array($label, NavigationTree::labelsFor($group), true)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The domain the current request is in.
     *
     * Resolved from the route rather than from which navigation item reports itself active,
     * because the screens most in need of a correct answer register no navigation item at all:
     * every report page sets `$shouldRegisterNavigation = false` and is reached from the Reports
     * hub, so an isActive() scan would leave the rail showing Home while the user reads a balance
     * sheet.
     *
     * Falls back to Home rather than to nothing. A page this cannot place — a package's own page,
     * a resource added without a group — still gets a usable column instead of an empty one.
     */
    public static function current(): string
    {
        $page = self::currentPageOrResource();

        if ($page === null) {
            return 'home';
        }

        foreach (self::DOMAINS as $key => $domain) {
            if (in_array($page, $domain['items'], true)) {
                return $key;
            }
        }

        return self::forGroup($page::getNavigationGroup()) ?? 'home';
    }

    /**
     * Keep only what belongs to one domain.
     *
     * The unlabelled group needs its items filtered rather than the group dropped, because that
     * one group holds items from more than one domain — see `items` in the map above.
     *
     * @param  array<NavigationGroup>  $groups
     * @return array<NavigationGroup>
     */
    public static function filter(array $groups, string $domain): array
    {
        $definition = self::DOMAINS[$domain];
        $kept = [];

        // The labels this domain actually owns at render time. A domain claims the group labels the
        // classes declare — "Employee" — and NavigationTree may have split that into branches by the
        // time the groups exist, so each claim is expanded through it. Keeping the declaration in
        // terms of declared labels is what lets a branch be added to the tree without a second edit
        // here, and what keeps the coverage test comparing like with like.
        $owned = [];

        foreach ($definition['groups'] as $group) {
            foreach (NavigationTree::labelsFor($group) as $label) {
                $owned[] = $label;
            }
        }

        // Hoisted: this resolves a URL per claimed page and each resolution asks canAccess().
        $claimed = self::urlsFor($definition['items']);

        foreach ($groups as $key => $group) {
            $label = $group->getLabel();

            if (filled($label)) {
                if (in_array($label, $owned, true)) {
                    $kept[$key] = $group;
                }

                continue;
            }

            // collect() rather than a cast: getItems() may hand back a Collection, and casting one
            // of those to array yields the object's properties instead of the items in it.
            $items = collect($group->getItems())
                ->filter(fn (NavigationItem $item): bool => in_array($item->getUrl(), $claimed, true))
                ->values()
                ->all();

            if ($items !== []) {
                // Cloned because the group came from the panel's own navigation and is reused:
                // mutating it would leave every later reader with one domain's items.
                $kept[$key] = (clone $group)->items($items);
            }
        }

        return $kept;
    }

    /**
     * The rail itself: one entry per domain that has something in it.
     *
     * A domain whose groups are all empty is omitted rather than shown leading nowhere — with 22
     * modules behind licences and per-role permissions, "empty" is the normal state of at least
     * one of these for most companies, and an icon that opens a blank column is worse than an icon
     * that is not there.
     *
     * @param  array<NavigationGroup>  $groups  the panel's full, unfiltered navigation
     * @return array<int, array{key: string, label: string, icon: string, url: string, active: bool}>
     */
    public static function rail(array $groups): array
    {
        $current = self::current();
        $rail = [];

        foreach (self::DOMAINS as $key => $domain) {
            $url = self::firstUrl(self::filter($groups, $key));

            if ($url === null) {
                continue;
            }

            $rail[] = [
                'key' => $key,
                'label' => $domain['label'],
                'icon' => $domain['icon'],
                'url' => $url,
                'active' => $key === $current,
            ];
        }

        return $rail;
    }

    /**
     * Where a rail icon goes: the first thing its column offers.
     *
     * @param  array<NavigationGroup>  $groups
     */
    private static function firstUrl(array $groups): ?string
    {
        foreach ($groups as $group) {
            foreach (collect($group->getItems()) as $item) {
                if (filled($url = $item->getUrl())) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, class-string>  $pages
     * @return array<int, string>
     */
    private static function urlsFor(array $pages): array
    {
        return array_values(array_filter(array_map(
            fn (string $page): ?string => $page::canAccess() ? $page::getUrl() : null,
            $pages,
        )));
    }

    /**
     * The page or resource class serving this request, by route name.
     *
     * Matching on route name rather than on the Livewire component means a resource's create and
     * edit screens resolve to the same domain as its listing, without naming each one.
     *
     * @return class-string|null
     */
    private static function currentPageOrResource(): ?string
    {
        $route = request()->route()?->getName();

        if ($route === null) {
            return null;
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel === null) {
            return null;
        }

        foreach ($panel->getPages() as $page) {
            if ($page::getRouteName($panel) === $route) {
                return $page;
            }
        }

        foreach ($panel->getResources() as $resource) {
            if (str_starts_with($route, $resource::getRouteBaseName($panel).'.')) {
                return $resource;
            }
        }

        return null;
    }

    private static function label(string|UnitEnum|null $group): ?string
    {
        if ($group instanceof UnitEnum) {
            return $group instanceof \Filament\Support\Contracts\HasLabel
                ? $group->getLabel()
                : $group->name;
        }

        return filled($group) ? $group : null;
    }
}
