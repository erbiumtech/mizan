<?php

namespace App\Support;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
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
     * **The domains are here; what goes in them is not.** A domain is a piece of shell design — its
     * label, its icon, and its position in the rail — and the six of them are the 3a taxonomy. Which
     * *groups* land in which domain is declared by the modules, in `module.php`, and merged by
     * `App\Support\ModuleManifest`. See docs/module-packaging-plan.md §5.
     *
     * The reason the two halves are split rather than one or the other winning: a group label is
     * **shared**. "Employee" is declared by ten modules, so ten of them claim it for People, and a
     * central list could not say which module put a screen there. A domain, by contrast, is claimed by
     * nobody — it exists because the design says the rail has six icons.
     *
     * `ModuleManifest` guards the two failures this arrangement makes possible: a label claimed for two
     * different domains, and a claim naming a domain that is not below. Both throw at build time, because
     * an unmapped or misrouted group appears in no column and its screens are then reachable only by URL.
     *
     * Two mappings the design does not settle, decided here and recorded here because the modules that
     * claim them cannot explain themselves:
     *
     *  - **Sales is its own domain**, as in 3a's rail. The 2c frame nests it under Finance. 3a is
     *    what was chosen, and a company that sells is in that domain all day.
     *  - **Personal (the employee's own tax estimate) sits in Home**, which the design never
     *    mentions. It is the one screen here that is about the person looking at it rather than
     *    about the company, which puts it beside the Dashboard rather than in People — People is
     *    where you go to act on *somebody else's* record.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    private const DOMAINS = [
        'home' => ['label' => 'Home', 'icon' => 'heroicon-o-home'],
        'reports' => ['label' => 'Reports', 'icon' => 'heroicon-o-chart-pie'],
        'finance' => ['label' => 'Finance', 'icon' => 'heroicon-o-banknotes'],
        // The seventh, added for the construction suite rather than folding it into Finance: Finance is
        // already 24 classes across three groups, and construction's four groups would push it past fifty
        // across seven — the flat-many-groups problem this class exists to solve. It costs a company that
        // never buys construction nothing, because `rail()` omits a domain whose groups are all empty.
        // docs/construction-management-plan.md §18.2 and its Phase 0.
        'construction' => ['label' => 'Construction', 'icon' => 'heroicon-o-building-office-2'],
        'people' => ['label' => 'People', 'icon' => 'heroicon-o-users'],
        'sales' => ['label' => 'Sales', 'icon' => 'heroicon-o-presentation-chart-line'],
        'admin' => ['label' => 'Admin', 'icon' => 'heroicon-o-cog-6-tooth'],
    ];

    /**
     * @return array<int, string>
     *
     * Reads the const alone, deliberately: `ModuleManifest` calls this while validating claims, so
     * consulting the manifest here would be a cycle.
     */
    public static function keys(): array
    {
        return array_keys(self::DOMAINS);
    }

    /**
     * A domain's label, icon, and the groups and ungrouped pages the modules put in it.
     *
     * @return array{label: string, icon: string, groups: array<int, string>, items: array<int, class-string>}
     */
    public static function definition(string $key): array
    {
        return self::DOMAINS[$key] + [
            'groups' => self::groupsIn($key),
            'items' => self::itemsIn($key),
        ];
    }

    /**
     * The group labels the modules claimed for one domain.
     *
     * @return array<int, string>
     */
    public static function groupsIn(string $key): array
    {
        return array_keys(array_filter(
            ModuleManifest::all()['navigation'] ?? [],
            fn (string $domain): bool => $domain === $key,
        ));
    }

    /**
     * The ungrouped pages the modules claimed for one domain.
     *
     * Filament collects every page that registers no group into one unlabelled group, which is why these
     * are claimed per class rather than per label — Dashboard and User Manual belong to Home while the
     * Reports hub is a domain of its own.
     *
     * @return array<int, class-string>
     */
    public static function itemsIn(string $key): array
    {
        return array_keys(array_filter(
            ModuleManifest::all()['navigation_items'] ?? [],
            fn (string $domain): bool => $domain === $key,
        ));
    }

    /** Every group label claimed by any domain. @return array<int, string> */
    public static function mappedGroups(): array
    {
        return array_keys(ModuleManifest::all()['navigation'] ?? []);
    }

    /** Every ungrouped page claimed by any domain. @return array<int, class-string> */
    public static function mappedItems(): array
    {
        return array_keys(ModuleManifest::all()['navigation_items'] ?? []);
    }

    /** Which domain owns a navigation group label, or null if the map has a hole in it. */
    public static function forGroup(string|UnitEnum|null $group): ?string
    {
        $label = self::label($group);

        if ($label === null) {
            return null;
        }

        $claims = ModuleManifest::all()['navigation'] ?? [];

        // The declared label, answered directly.
        if (isset($claims[$label])) {
            return $claims[$label];
        }

        // Also by branch label, so this answers for either name. Pages resolve their domain
        // through here from the group they *declare* ("Employee"), while anything reading a
        // rendered group sees the branch it was split into ("Payroll") — both have to land in
        // the same domain or the rail and the column disagree about where you are.
        foreach ($claims as $claimed => $key) {
            if (in_array($label, NavigationTree::labelsFor($claimed), true)) {
                return $key;
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

        $claimed = ModuleManifest::all()['navigation_items'] ?? [];

        if (isset($claimed[$page])) {
            return $claimed[$page];
        }

        return self::forGroup($page::getNavigationGroup()) ?? 'home';
    }

    /**
     * Keep only what belongs to one domain.
     *
     * The unlabelled group needs its items filtered rather than the group dropped, because that
     * one group holds items from more than one domain — see `itemsIn()`.
     *
     * @param  array<NavigationGroup>  $groups
     * @return array<NavigationGroup>
     */
    public static function filter(array $groups, string $domain): array
    {
        $definition = self::definition($domain);
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
     * Each entry carries its domain's whole tree as well, because the rail is now the only navigation
     * on screen by default: hovering an icon opens that domain's tree in a flyout, so every domain's
     * groups are needed on every page rather than just the open one's. They come out of the same
     * per-request snapshot the column reads, so this costs a filter per domain and no extra queries.
     *
     * @param  array<NavigationGroup>  $groups  the panel's full, unfiltered navigation
     * @return array<int, array{key: string, label: string, icon: string, url: string, active: bool, columns: array<int, array<NavigationGroup>>}>
     */
    public static function rail(array $groups): array
    {
        $current = self::current();
        $rail = [];

        foreach (self::DOMAINS as $key => $domain) {
            $owned = self::filter($groups, $key);
            $url = self::firstUrl($owned);

            if ($url === null) {
                continue;
            }

            $rail[] = [
                'key' => $key,
                'label' => $domain['label'],
                'icon' => $domain['icon'],
                'url' => $url,
                'active' => $key === $current,
                'columns' => self::columns($owned),
            ];
        }

        return $rail;
    }

    /**
     * How many rows a flyout column may hold before the next group starts a new one.
     *
     * 14 is what fits above the fold at the smallest laptop height this application is used on
     * (768px) once the flyout's own padding is taken off. It is a *row* budget rather than a pixel
     * one because the rows are a fixed height, which is what lets this be decided in PHP and
     * asserted in a test instead of measured in a browser.
     */
    private const COLUMN_ROWS = 14;

    /**
     * Splits a domain's groups into columns that fit without scrolling.
     *
     * This is the whole point of the flyout: People holds 27 screens across eight branches, and a
     * flyout that scrolls is worse than the column it replaced — you cannot see what you are choosing
     * between, and a scroll inside a hover panel is lost the moment the pointer leaves it. So the
     * groups are packed into columns here and the flyout grows sideways, where there is room.
     *
     * Greedy rather than balanced: groups stay in their declared order, and a group is never split
     * across two columns, because a heading in one column with its items in the next reads as two
     * different groups. A group longer than the budget gets a column to itself and overflows it,
     * which is the one case this cannot fix without breaking that rule — NavigationTree keeps the
     * branches short enough that it does not arise, and NavigationDomainsTest fails if it starts to.
     *
     * @param  array<NavigationGroup>  $groups
     * @return array<int, array<NavigationGroup>>
     */
    public static function columns(array $groups): array
    {
        $columns = [];
        $column = [];
        $rows = 0;

        foreach ($groups as $group) {
            // The heading counts as a row; so does every item under it.
            $height = 1 + count(collect($group->getItems())->all());

            if ($column !== [] && $rows + $height > self::COLUMN_ROWS) {
                $columns[] = $column;
                $column = [];
                $rows = 0;
            }

            $column[] = $group;
            $rows += $height;
        }

        if ($column !== []) {
            $columns[] = $column;
        }

        return $columns;
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
