<?php

namespace App\Support;

/**
 * Relation managers one module contributes to another module's resource.
 *
 * The problem this solves is one-directional. `projects` **requires** `employees`, so Projects may name
 * Employees freely — but the Employee screen wanted a Projects tab, and a resource naming a relation
 * manager from a module that already depends on it is a cycle, which composer cannot express at all.
 * The fix is not to hide the reference but to reverse it: Employees offers a slot, Projects fills it, and
 * Employees never learns that Projects exists.
 *
 * **Why not a string class name on the resource.** Because that is the same edge with the evidence
 * removed. `docs/module-packaging-plan.md` records phase 0 finding exactly this — Payroll reaching
 * Advances through the container "with no import, because an import would make the pair a cycle" — and
 * names it for what it is: the cycle being avoided in the lint rather than in the code. A string still
 * needs the class on disk, so composer would still need the edge; all that changes is that nothing can
 * see it any more.
 *
 * Registered from the contributing module's service provider, which is where the licence guard already
 * lives: a module that is not enabled never registers, so the tab is absent rather than empty.
 */
class ResourceContributions
{
    /**
     * Contributions, keyed by the resource they are added to.
     *
     * @var array<class-string, array<int, class-string>>
     */
    private static array $relationManagers = [];

    /**
     * @param  class-string  $resource  the resource being extended
     * @param  class-string  $relationManager  the manager to add to it
     */
    public static function addRelationManager(string $resource, string $relationManager): void
    {
        // Idempotent: providers boot once per process, but a test that re-registers should not end up
        // rendering the same tab twice.
        if (! in_array($relationManager, self::$relationManagers[$resource] ?? [], true)) {
            self::$relationManagers[$resource][] = $relationManager;
        }
    }

    /**
     * @param  class-string  $resource
     * @return array<int, class-string>
     */
    public static function relationManagersFor(string $resource): array
    {
        return self::$relationManagers[$resource] ?? [];
    }

    /** For tests that need to assert what a resource looks like without contributions. */
    public static function flush(): void
    {
        self::$relationManagers = [];
    }
}
