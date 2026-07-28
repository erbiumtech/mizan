<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one approval inbox carry more than employee-profile edits.
 *
 * `target_type` says which record the requested changes belong to, and
 * `target_id` points at that row for targets other than the employee itself
 * (an employee has many salary settings, one per period). Existing rows are all
 * profile edits, so the default backfills them correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_change_requests', function (Blueprint $table) {
            $table->string('target_type')
                ->default('employee')
                ->after('employee_id')
                ->comment('employee | employee_setting');

            $table->unsignedBigInteger('target_id')
                ->nullable()
                ->after('target_type')
                ->comment('row the changes apply to; null when the target is the employee');

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_change_requests', function (Blueprint $table) {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropColumn(['target_type', 'target_id']);
        });
    }
};
