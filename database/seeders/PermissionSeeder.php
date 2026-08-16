<?php

namespace Database\Seeders;

use App\Support\ModuleManifest;
use App\Support\PermissionCache;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;

/**
 * Writes every permission the installed modules declare.
 *
 * The 245 rows this used to hold as one literal array now live beside the code
 * they protect, in each module's `module.php`. That is what makes a module one
 * directory instead of a directory plus an edit here — and it is what stops the
 * list and the policies drifting apart, since a module that adds a policy check
 * and forgets the row is a module whose panel 500s (`hasPermissionTo()` throws
 * for an unknown name rather than denying).
 *
 * Two things are deliberately done **once for the whole run** rather than per
 * module, and both are bugs if inverted — see docs/module-packaging-plan.md §5.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = ModuleManifest::all()['permissions'] ?? [];

        // Discovery finding nothing would wipe nothing and seed nothing, and the
        // first symptom would be every policy check throwing. A seeder that
        // silently does nothing is the failure this guard exists for.
        if (count($permissions) < 100) {
            throw new RuntimeException(sprintf(
                'Only %d permissions were discovered across %d module manifests. '
                .'Expected the full set — check that app/Modules/*/module.php are present.',
                count($permissions),
                count(ModuleManifest::manifestPaths()),
            ));
        }

        // A name may appear once. ModuleManifest already refuses to merge two
        // modules declaring the same name; this catches a module repeating itself.
        $names = array_column($permissions, 'name');
        $repeated = array_values(array_unique(array_diff_assoc($names, array_unique($names))));

        if ($repeated !== []) {
            throw new RuntimeException(
                'These permissions are declared more than once: '.implode(', ', $repeated)
            );
        }

        // Matched on `name` alone, not on name AND group. Matching on both meant a
        // regrouped permission created a second row carrying the same name while
        // every existing role stayed attached to the first — silently, because
        // nothing in the stack compares the two. Matching on the name updates the
        // group in place, which is what a regroup always meant.
        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                ['group' => $permission['group']],
            );
        }

        // Once, at the end, and across every company. Spatie invalidates its own cache on
        // write, but only the copy belonging to the context doing the writing — and a seeder
        // has no company, so each company kept serving the list it had cached before this ran.
        // A permission added here and not visible there is not a stale menu: policies call
        // hasPermissionTo(), which throws for a name it cannot find, and the panel 500s.
        //
        // Per module rather than once would be worse than wasteful: this discards and
        // rebuilds the PermissionRegistrar singleton, carrying the team id across by hand,
        // so running it between modules is how a later RoleSeeder throws RoleDoesNotExist
        // with a message that says nothing about caches.
        PermissionCache::flushEverywhere();
    }
}
