<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What RoleSeeder last granted each system role.
 *
 * RoleSeeder runs on every deploy and used to `syncPermissions()`, so a company's own edits to its
 * Manager or Accountant were undone by the next release. With this ledger it applies only what the
 * code changed since it last ran — a grant the company took away stays away, one it added stays —
 * which is what SystemRoles has always said it does. Null means "seeded before the ledger": the next
 * run grants what is new and revokes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = config('permission.table_names.roles', 'roles');

        if (! Schema::hasColumn($roles, 'seeded_permissions')) {
            Schema::table($roles, function (Blueprint $table): void {
                $table->json('seeded_permissions')->nullable();
            });
        }
    }

    public function down(): void
    {
        $roles = config('permission.table_names.roles', 'roles');

        if (Schema::hasColumn($roles, 'seeded_permissions')) {
            Schema::table($roles, function (Blueprint $table): void {
                $table->dropColumn('seeded_permissions');
            });
        }
    }
};
