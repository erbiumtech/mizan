<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal/tax entity, separate from `type` and `profile` — it decides which
 * tax engine a company's return pack uses. See docs/legal-entity-types-plan.md.
 *
 * Nullable with no default and no backfill, deliberately: null reads as the
 * entity `type` implies (`company` for business, `individual` for personal), so
 * every existing company keeps its current tax treatment and the column can ship
 * dark until an operator sets it. Unlike `type` — which is create-only because it
 * seeds the chart — this one may be changed after provisioning, because
 * reclassifying an entity for tax touches no books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('legal_entity')->nullable()->after('profile')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('legal_entity');
        });
    }
};
