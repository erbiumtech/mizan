<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('unit')->default('pcs');
            $table->enum('valuation_method', ['fifo', 'lifo', 'average'])->default('fifo');
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->enum('type', ['purchase', 'sale', 'adjustment']);
            $table->decimal('quantity', 12, 2)->comment('Signed: purchases positive, sales negative');
            $table->decimal('unit_cost', 12, 2)->nullable()->comment('Purchases/positive adjustments');
            $table->decimal('unit_price', 12, 2)->nullable()->comment('Sales');
            $table->decimal('total_cost', 14, 2)->nullable()->comment('COGS applied to sales/write-offs');
            $table->decimal('remaining_quantity', 12, 2)->default(0)->comment('Unconsumed part of a purchase lot (FIFO/LIFO)');
            $table->date('movement_date');
            $table->string('reference')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['product_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('products');
    }
};
