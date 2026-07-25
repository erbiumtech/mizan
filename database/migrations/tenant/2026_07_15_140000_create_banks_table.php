<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('bank_code')->unique()->comment('IMD code for IBFT (from SBP bank directory)');
            $table->string('bank_name');
            $table->string('bank_short_code')->nullable()->comment('Common abbreviation, e.g. HBL, MCB');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // employees.bank_id is declared in create_employees_table without a
        // constraint because banks migrates later — add the FK here.
        if (Schema::hasColumn('employees', 'bank_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Order-proof: remove the employees FK if a later migration's
        // rollback has not already done so.
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'bank_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropConstrainedForeignId('bank_id');
            });
        }

        Schema::dropIfExists('banks');
    }
};
