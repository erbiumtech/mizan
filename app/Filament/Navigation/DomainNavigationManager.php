<?php

namespace App\Filament\Navigation;

use App\Support\NavigationDomains;
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

        return NavigationDomains::filter(
            app(NavigationSnapshot::class)->groups(),
            NavigationDomains::current(),
        );
    }
}
