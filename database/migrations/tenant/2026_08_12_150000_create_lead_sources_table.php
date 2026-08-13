<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a lead came from — rows, not an enum.
 *
 * Reference data for the same reason leave types are: every company has its own
 * answer ("Expo 2026", "referral from Ali", "LinkedIn"), and a config array would
 * make adding one a deploy.
 *
 * It earns its own table rather than a free-text column on `leads` because of what
 * it is *for*: win/loss by source is the report docs/crms-plan.md §8 says is worth
 * more than the forecast, and a free-text field gives you "LinkedIn", "Linkedin" and
 * "linked in" as three sources with three win rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();

            // Unique because the whole point is grouping: two rows named "Referral"
            // split the referral win rate in half and nothing reports that they did.
            $table->string('name')->unique();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
