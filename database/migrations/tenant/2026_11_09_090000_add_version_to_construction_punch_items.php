<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A punch item's version — docs/desktop-mobile-plan.md §5a rule 4, built in O2.
 *
 * A snag is the one field document two people change: the foreman marks it ready on a phone that has
 * been dark since lunch, while the site manager in the office has already failed it at re-inspection.
 * An offline change says which version it was made against; the server applies it only if that is
 * still the item's version, and otherwise keeps its own value and files the device's for a human.
 *
 * An integer bumped on every save rather than `updated_at`, because two saves in the same second are
 * the ordinary case on a busy list (an inspection writes the inspection and then the item) and a
 * timestamp would call them one version. Existing rows start at 0, which is what a device that has
 * never read them will not claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('construction_punch_items', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('construction_punch_items', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
