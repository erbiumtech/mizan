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
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
