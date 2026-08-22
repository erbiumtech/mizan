<?php

namespace App\Filament\Navigation;

use App\Support\NavigationDomains;
use App\Support\NavigationTree;
use Closure;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationManager;

/**
 * Narrows the sidebar to the domain the request is in.
 *
 * This is the second level of the two-level shell: the rail picks a domain, this decides what the
 * column beside it contains. Extending Filament's own manager rather than replacing the navigation
 * with `Panel::navigation()` is deliberate — that closure would put the whole tree in this
 * application's hands, and every resource's group, sort, icon, badge and visibility check would
 * have to be restated here and then kept in step with 101 classes. Filtering the finished result
 * leaves all of that where it already works and gives one place to change.
 *
 * Bound in AdminPanelProvider. Filament resolves the manager through the container on each
 * `getNavigation()` call, which is what makes this substitution possible without touching a view.
 */
class DomainNavigationManager extends NavigationManager
{
    /**
     * Whether to narrow. Off only while NavigationSnapshot assembles the full tree, which it
     * needs in order to know which domains have anything in them.
     */
    private static bool $filtering = true;

    /**
     * Run a callback with filtering off.
     *
     * A static flag rather than a constructor argument because the instance is built by the
     * container inside Filament's own call, where this application has nothing to pass.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutFiltering(Closure $callback): mixed
    {
        $previous = self::$filtering;
        self::$filtering = false;

        try {
            return $callback();
        } finally {
            // Restored rather than set true: nested calls must not turn filtering back on for the
            // caller that had it off.
            self::$filtering = $previous;
        }
    }

    /** @return array<NavigationGroup> */
    public function get(): array
    {
        if (! self::$filtering) {
            $this->branch();

            return parent::get();
        }

        // Every panel resolves this class, only one panel has domains. See NavigationDomains::PANEL
        // for why the platform panel must come through untouched.
        //
        // `$this->panel` is the panel this manager was constructed for, and because the binding is
        // scoped that is the panel the current request is on. So a request to /platform builds its
        // navigation through this class and passes straight through it.
        if ($this->panel->getId() !== NavigationDomains::PANEL) {
            return parent::get();
        }

        return $this->collapsed(NavigationDomains::filter(
            app(NavigationSnapshot::class)->groups(),
            NavigationDomains::current(),
        ));
    }

    /**
     * Splits the long groups into branches, before Filament groups anything.
     *
     * Has to happen here rather than on the finished groups: the grouping is what turns an item's
     * group label into a NavigationGroup, so a label rewritten afterwards would arrive too late.
     * Mounting is what fills in the items — the panel's pages and resources register into this
     * instance — so it is forced first rather than left to parent::get(), which would then find the
     * work already done and skip it.
     *
     * Only runs on the unfiltered path, which is the one that mounts. The filtered path reads the
     * request's snapshot, and the snapshot is built through here.
     */
    private function branch(): void
    {
        if (! $this->isNavigationMounted) {
            $this->mountNavigation();
        }

        foreach ($this->navigationItems as $item) {
            NavigationTree::apply($item);
        }
    }

    /**
     * Groups start closed, except the one holding the page you are on.
     *
     * This is only a *seed*. Filament writes these labels into localStorage the first time a person
     * loads the panel and reads their own toggles from then on, so what this decides is the state on
     * day one — after that the column is however they left it, which is the right way round.
     *
     * The seed has to be paired with the script in the sidebar-groups partial: because the stored
     * state wins, a group somebody collapsed stays collapsed when they later navigate *into* it, and
     * they would land on a page whose entry in the column is hidden. That script opens the active
     * branch; this decides where everyone starts.
     *
     * Cloned before touching: these groups come from the request's shared snapshot, and collapsing
     * one in place would collapse it for every later reader of the same array.
     *
     * @param  array<NavigationGroup>  $groups
     * @return array<NavigationGroup>
     */
    private function collapsed(array $groups): array
    {
        return array_map(
            function (NavigationGroup $group): NavigationGroup {
                // The unlabelled group is left alone. It has no header to click, so there would be
                // no way to reopen it — and Filament's seeding script maps the collapsed groups
                // through a `: string` closure on getLabel(), so marking a group with no label
                // collapsed is a TypeError that takes out the whole sidebar.
                if (blank($group->getLabel())) {
                    return $group;
                }

                // collapsible() again afterwards, and it is not redundant: NavigationGroup::collapsed()
                // passes its own argument straight on to collapsible(), so seeding the group holding
                // the current page as *not* collapsed also declares it *not* collapsible — it renders
                // with no chevron and no way to close the branch you are in. Restoring it separates
                // the two questions, which is how they read from the outside.
                return (clone $group)
                    ->collapsed(! $group->isActive())
                    ->collapsible();
            },
            $groups,
        );
    }
}
