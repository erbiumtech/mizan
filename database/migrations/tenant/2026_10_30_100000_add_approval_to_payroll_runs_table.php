<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approved state between open and locked, and the staleness mark —
 * docs/saas-launch-plan.md §14.1. `status` was an enum of two; a string now,
 * because a third state is a migration either way and the next will not be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->string('status', 16)->default('open')->change();
        });

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->index()->after('notes');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('sent_back_by')->nullable()->index()->after('approved_at');
            $table->timestamp('sent_back_at')->nullable()->after('sent_back_by');
            $table->string('sent_back_reason')->nullable()->after('sent_back_at');
            // When the run last read its inputs — attendance, leave, claims, advances, the
            // component input store. Anything newer is what the page warns about.
            $table->timestamp('inputs_as_of')->nullable()->after('sent_back_reason');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn(['approved_by', 'approved_at', 'sent_back_by', 'sent_back_at', 'sent_back_reason', 'inputs_as_of']);
        });
    }
};
