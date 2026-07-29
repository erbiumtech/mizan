<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a fiscal year be closed, so the ledger for a finished period can be
 * frozen.
 *
 * `closed_by` is a soft reference to the landlord `users` table — a real foreign
 * key cannot span the tenant/landlord database boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('is_active');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closed_by']);
        });
    }
};
