<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claims get lines, a draft state and a two-step chain — docs/saas-launch-plan.md §14.3.
 * A category is the company's own list, each mapped to the transaction type it
 * posts as, so the ledger does not move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table): void {
            $table->string('status', 16)->default('pending')->change();
        });

        Schema::table('expense_claims', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
        });

        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types')->nullOnDelete();
            $table->boolean('requires_receipt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expense_claim_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained('expense_claims')->cascadeOnDelete();
            $table->date('spent_on');
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->string('receipt_path')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('expense_claim_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained('expense_claims')->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->string('role', 60)->nullable();
            $table->foreignId('approved_by')->index();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['expense_claim_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_approvals');
        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_categories');

        Schema::table('expense_claims', function (Blueprint $table): void {
            $table->dropColumn('submitted_at');
        });
    }
};
