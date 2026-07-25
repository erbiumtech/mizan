<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('E.g., 1000, 2100, 5100');
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->enum('normal_balance', ['debit', 'credit'])->default('debit');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_manual_entry')->default(true);
            $table->text('description')->nullable();
            $table->decimal('balance', 15, 2)->default(0)->comment('Cached; maintained by posting');
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
