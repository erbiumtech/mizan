<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employees are auto-created as stubs when a user is created, before
        // gender is known, so the column must accept null.
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('gender', ['Male', 'Female'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('gender', ['Male', 'Female'])->nullable(false)->change();
        });
    }
};
