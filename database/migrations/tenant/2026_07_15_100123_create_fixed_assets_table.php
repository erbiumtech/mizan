<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code')->unique()->comment('E.g., FA-0001');
            $table->string('name');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete()
                ->comment('Asset account holding the cost, e.g. 1400 Office Equipment');
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 15, 2);
            $table->enum('depreciation_method', ['straight_line', 'declining_balance'])->default('straight_line');
            $table->unsignedInteger('useful_life_months');
            $table->decimal('salvage_value', 15, 2)->default(0);
            $table->decimal('accumulated_depreciation', 15, 2)->default(0)->comment('Cached; maintained by DepreciationService');
            $table->enum('status', ['active', 'fully_depreciated', 'disposed'])->default('active');
            $table->timestamp('disposed_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()
                ->comment('Optional link to the purchase entry');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
