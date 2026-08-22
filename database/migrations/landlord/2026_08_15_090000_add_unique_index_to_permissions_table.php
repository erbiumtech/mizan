<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A permission name must be unique. It never was.
 *
 * `create_permission_tables.php` puts unique keys on `roles` and on both pivots
 * and leaves `permissions` as `id, name, group, guard_name, timestamps` with no
 * key at all. `PermissionSeeder` then matched on `name` AND `group`, so changing
 * a permission's group created a **second row with the same name** instead of
 * moving the first. Roles keep pointing at the old row, new grants attach to the
 * new one, `Permission::findByName()` returns whichever it finds first, and
 * nothing anywhere reports it — the grant simply stops working for some people.
 *
 * `docs/new-module-checklist.md` §6 records this as trap 1 and tells authors to
 * treat a regroup as a data migration. That is the right instruction and the
 * wrong place to enforce it: an instruction in a document is not a constraint.
 *
 * This is a prerequisite for docs/module-packaging-plan.md §4. Once each module
 * contributes its own permission rows, two modules can ship the same name
 * independently, and a collision stops being an editing mistake that one careful
 * person can avoid and becomes a structural possibility nobody can see.
 *
 * The table is landlord-side — one table for the whole installation, roles are
 * per-company through spatie teams — so this is one cleanup, not one per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        $duplicates = DB::table($table)
            ->select('name', 'guard_name', DB::raw('COUNT(*) as total'))
            ->groupBy('name', 'guard_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        // Deliberately fail rather than pick a survivor. Choosing which row to
        // keep decides which roles keep their grant, and that is a judgement
        // about this installation's data that a migration cannot make. Merging
        // the pivots by hand is a few minutes; discovering months later that a
        // migration silently dropped a role's permissions is not.
        if ($duplicates->isNotEmpty()) {
            $lines = $duplicates
                ->map(fn ($row) => sprintf('  %s (guard %s) x%d', $row->name, $row->guard_name, $row->total))
                ->all();

            throw new RuntimeException(implode("\n", [
                'Cannot add a unique index: these permission names already exist more than once.',
                '',
                ...$lines,
                '',
                'Each duplicate is a name some roles are granted through one row and some',
                'through another. Merge them before migrating: repoint role_has_permissions',
                'and model_has_permissions at the row you are keeping, delete the others,',
                'then run this again.',
            ]));
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unique(['name', 'guard_name'], 'permissions_name_guard_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.permissions', 'permissions'), function (Blueprint $blueprint) {
            $blueprint->dropUnique('permissions_name_guard_name_unique');
        });
    }
};
