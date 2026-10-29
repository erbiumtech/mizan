<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which appraisal a salary proposal came from — docs/saas-launch-plan.md §7.10.
 *
 * Nullable, no foreign key: Employees may not name Performance, and a request
 * raised by hand has no review. The appraisal screens read it the other way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_change_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('review_id')->nullable()->after('target_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('employee_change_requests', function (Blueprint $table): void {
            $table->dropColumn('review_id');
        });
    }
};
