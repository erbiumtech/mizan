<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What shape of business this company is: services, trading, manufacturing and
 * so on. The companion to `type`, and deliberately not more values of it.
 *
 * `type` decides the irreversible half — which chart of accounts and which
 * spending categories were seeded — and is therefore fixed at creation. This
 * decides which modules the company starts licensed and which baseline it is
 * seeded, and then goes on answering "what is recommended for this company"
 * for the rest of its life. That second job is why it stays editable where
 * `type` does not.
 *
 * NULLABLE, AND NOT BACKFILLED. Every company that exists today was licensed by
 * hand from the registry defaults, and null says exactly that. Guessing a
 * profile for them would put a label on the licensing screen that their actual
 * licences contradict, and would claim their tenant database was seeded from a
 * profile that did not exist when it was built.
 *
 * See config/company_profiles.php and docs/company-profiles-plan.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('profile')->nullable()->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('profile');
        });
    }
};
