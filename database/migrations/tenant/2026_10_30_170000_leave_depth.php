<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Leave groups, eligibility, encashment — docs/saas-launch-plan.md §14.4. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->json('eligible_genders')->nullable()->after('is_encashable');
            $table->unsignedSmallInteger('min_service_months')->nullable()->after('eligible_genders');
            $table->json('eligible_employment_types')->nullable()->after('min_service_months');
            $table->unsignedSmallInteger('max_consecutive_days')->nullable()->after('eligible_employment_types');
            $table->unsignedSmallInteger('document_after_days')->nullable()->after('max_consecutive_days');
            $table->string('colour', 7)->nullable()->after('document_after_days');
            $table->decimal('pay_percent', 5, 2)->default(100)->after('colour');
        });

        Schema::create('leave_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('leave_group_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_group_id')->constrained('leave_groups')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unique(['leave_group_id', 'leave_type_id']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('leave_group_id')->nullable()->after('location_id')->constrained('leave_groups')->nullOnDelete();
        });

        Schema::create('leave_encashments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->decimal('days', 5, 1);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('month', 12)->nullable();
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->index();
            $table->foreignId('decided_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->foreignId('leave_adjustment_id')->nullable()->constrained('leave_adjustments')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_encashments');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('leave_group_id');
        });
        Schema::dropIfExists('leave_group_types');
        Schema::dropIfExists('leave_groups');
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn(['eligible_genders', 'min_service_months', 'eligible_employment_types', 'max_consecutive_days', 'document_after_days', 'colour', 'pay_percent']);
        });
    }
};
