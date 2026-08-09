<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('What this standing entry is: "Office rent", "Vehicle loan instalment"');
            $table->text('memo')->nullable()->comment('Copied onto every entry raised');
            $table->string('reference')->nullable();
            $table->enum('entry_type', ['general', 'adjusting', 'closing', 'reversing'])->default('general');

            // An interval in months rather than an enum of names, so quarterly is
            // 3 and there is one arithmetic to test instead of four branches.
            $table->unsignedSmallInteger('interval_months')->default(1);

            // 1-31, clamped to the month's length when it is raised: a schedule
            // set to the 31st must still fire in February rather than skipping it.
            $table->unsignedTinyInteger('day_of_month')->default(1);

            $table->date('starts_on');
            $table->date('ends_on')->nullable()->comment('Open-ended when null');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_on']);
        });

        Schema::create('scheduled_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_transaction_lines');
        Schema::dropIfExists('scheduled_transactions');
    }
};
