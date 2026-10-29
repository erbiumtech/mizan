<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The small facts a company record was short of — docs/saas-launch-plan.md §14.10.
 *
 * Three unrelated tables in one migration because they are one decision: the
 * walkthrough's company details against ours, and these are the fields it had and this
 * did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            // A branch somebody has to ring, and a pin somebody has to find. The map link
            // is a URL rather than a second pair of coordinates: the geofence already has
            // those, and what a person wants on a branch list is something to tap.
            $table->string('phone', 40)->nullable()->after('city');
            $table->string('map_url')->nullable()->after('phone');
            // Which one the letterhead means. A company with five branches has one
            // registered office, and documents are issued from it.
            $table->boolean('is_head_office')->default(false)->after('map_url');
        });

        Schema::table('banks', function (Blueprint $table): void {
            // For a payment that leaves the country. The IMD code is what the local
            // IBFT file needs; SWIFT is what a foreign remitter asks for.
            $table->string('swift_code', 16)->nullable()->after('bank_short_code');
            // The four letters a Pakistani IBAN carries for this bank, so an account
            // number typed against the wrong bank can be spotted before a payment file.
            $table->string('iban_prefix', 8)->nullable()->after('swift_code');
        });
    }

    public function down(): void
    {
        Schema::table('banks', fn (Blueprint $table) => $table->dropColumn(['swift_code', 'iban_prefix']));
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn(['phone', 'map_url', 'is_head_office']));
    }
};
