<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique()->comment('JE-YYYY-NNNNNN');
            $table->date('entry_date');
            $table->string('reference')->nullable();
            $table->text('memo')->nullable();
            $table->enum('entry_type', ['general', 'adjusting', 'closing', 'reversing'])->default('general');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected', 'posted'])->default('draft');
            $table->foreignId('approved_by')->nullable()->index(); // soft ref -> landlord users
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->index(); // soft ref -> landlord users
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index('entry_date');
            $table->index('status');
            $table->index('is_posted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
