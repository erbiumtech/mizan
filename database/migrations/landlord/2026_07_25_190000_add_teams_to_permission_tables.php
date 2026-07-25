<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrades an EXISTING permission schema (created before spatie teams were
 * enabled) to be team-aware. Guarded by hasColumn so it is a no-op on fresh
 * installs — where create_permission_tables already added the team columns — and
 * therefore never runs its (SQLite-unfriendly) primary-key changes under the
 * test suite. Intended for the live landlord (MySQL) database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = config('permission.table_names');
        $team = config('permission.column_names.team_foreign_key', 'company_id');
        $morph = config('permission.column_names.model_morph_key', 'model_id');

        if (! Schema::hasColumn($names['roles'], $team)) {
            Schema::table($names['roles'], function (Blueprint $table) use ($team) {
                $table->unsignedBigInteger($team)->nullable()->after('id');
                $table->index($team, 'roles_team_foreign_key_index');
            });
        }

        if (! Schema::hasColumn($names['model_has_roles'], $team)) {
            // Give the role_id FK its own index so the composite PRIMARY can be dropped.
            Schema::table($names['model_has_roles'], fn (Blueprint $t) => $t->index('role_id', 'model_has_roles_role_id_index'));

            Schema::disableForeignKeyConstraints();
            Schema::table($names['model_has_roles'], function (Blueprint $table) use ($team, $morph) {
                $table->dropPrimary();
                $table->unsignedBigInteger($team)->default(0)->after('role_id');
                $table->index($team, 'model_has_roles_team_foreign_key_index');
                $table->primary([$team, 'role_id', $morph, 'model_type'], 'model_has_roles_role_model_type_primary');
            });
            Schema::enableForeignKeyConstraints();
        }

        if (! Schema::hasColumn($names['model_has_permissions'], $team)) {
            Schema::table($names['model_has_permissions'], fn (Blueprint $t) => $t->index('permission_id', 'model_has_permissions_permission_id_index'));

            Schema::disableForeignKeyConstraints();
            Schema::table($names['model_has_permissions'], function (Blueprint $table) use ($team, $morph) {
                $table->dropPrimary();
                $table->unsignedBigInteger($team)->default(0)->after('permission_id');
                $table->index($team, 'model_has_permissions_team_foreign_key_index');
                $table->primary([$team, 'permission_id', $morph, 'model_type'], 'model_has_permissions_permission_model_type_primary');
            });
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // Non-reversible team upgrade; leave columns in place.
    }
};
