<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structures, adjustments, the import batch and the reminder count —
 * docs/saas-launch-plan.md §14.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_structures', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('salary_structure_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->cascadeOnDelete();
            // share: a percentage of gross; fixed: an amount; balance: whatever gross has left.
            $table->string('mode', 10)->default('share');
            $table->decimal('value', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['salary_structure_id', 'pay_component_id']);
        });

        Schema::create('pay_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->string('month', 12);
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'fiscal_year_id', 'month']);
        });

        Schema::table('payslips', function (Blueprint $table): void {
            $table->string('import_batch', 40)->nullable()->index()->after('sent_at');
            $table->unsignedTinyInteger('acknowledgement_reminders')->default(0)->after('import_batch');
            $table->timestamp('last_reminded_at')->nullable()->after('acknowledgement_reminders');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn(['import_batch', 'acknowledgement_reminders', 'last_reminded_at']);
        });

        Schema::dropIfExists('pay_adjustments');
        Schema::dropIfExists('salary_structure_lines');
        Schema::dropIfExists('salary_structures');
    }
};
