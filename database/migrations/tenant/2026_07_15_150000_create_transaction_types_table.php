<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique()->comment('Slug, e.g. salary, rent, food');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete()
                ->comment('Default expense/liability account for this type');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('transaction_type_id')->nullable()->after('fiscal_year_id')
                ->constrained('transaction_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_type_id');
        });

        Schema::dropIfExists('transaction_types');
    }
};
