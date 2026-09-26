<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user UI preferences — the store `docs/table-context-menu-plan.md` Phase 4 deferred.
 *
 * On `users` (landlord) rather than a tenant table, because these follow the person, not the
 * company: the right-click menu's off switch should hold when the same user switches companies.
 * `dashboard_layouts` was considered and rejected — it is tenant-scoped and allow-lists widget
 * arrangement keys by design, so a UI flag has no home there.
 *
 * One json column rather than a column per flag: never queried by key, read whole with the user
 * row that every request already loads. The allow-list of storable keys lives on the endpoint
 * (routes/web.php `user.preferences`), the same posture DashboardLayout::KEYS takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
