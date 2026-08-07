<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of tenant this is: a business, or one person's own affairs.
 *
 * A personal account is a tenant like any other — it gets its own database, its
 * own Administrator / Accountant / Manager / Employee roles, its own chart of
 * accounts and its own staff. That is the whole point: somebody keeping their
 * own books may want an accountant to do it for them and may employ a driver or
 * a cook, and all of that machinery already exists per tenant.
 *
 * So this column changes almost nothing structurally. It decides what the thing
 * is *called* on screen, which modules it starts with, and which chart of
 * accounts it is seeded with. Everything else works because a household is, for
 * these purposes, a very small organisation.
 *
 * Existing rows are businesses, which is what they were before the distinction
 * existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('type')->default('business')->after('slug')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
