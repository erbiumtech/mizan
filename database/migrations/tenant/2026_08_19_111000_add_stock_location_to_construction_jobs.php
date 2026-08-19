<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job's site store — `docs/construction-management-plan.md` §6.
 *
 * The construction half of §6's decision: the location belongs to Inventory, and each module points at it. This is the
 * pointer. `stores.stock_location_id` is the retail half and belongs to that plan's own phase, which has not built
 * `stores` yet.
 *
 * **A separate migration from the table itself, deliberately.** The `stock_locations` migration is Inventory's and
 * serves two plans; this column is construction's. Keeping them apart means the retail plan can read the first one
 * without wondering why a construction table is in it, and either can be reasoned about alone.
 *
 * Nullable, and the null is meaningful rather than missing: a job with no site store buys everything direct to the work
 * face, which §6 calls "the default" and says "works with Inventory unlicensed" — most contractors, most of the time.
 * The column exists in every tenant because licensing decides what is *offered* rather than what is migrated, the same
 * treatment `construction_jobs.project_id` already gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('construction_jobs', function (Blueprint $table) {
            $table->foreignId('stock_location_id')->nullable()->after('project_id')
                ->constrained('stock_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('construction_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_location_id');
        });
    }
};
