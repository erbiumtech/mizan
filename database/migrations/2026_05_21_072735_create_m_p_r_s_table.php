<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mprs', function (Blueprint $table) {
            $table->id();

            // 1. User ke sath relationship (Dropdown ke liye)
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // 2. Date Picker ke liye column
            $table->date('mpr_date')->nullable();

            // 3 se 7: Saare Rich Text fields database mein 'text' ya 'longText' hotay hain
            $table->longText('feedback')->nullable();
            $table->longText('topics_scope')->nullable();
            $table->longText('recent_module')->nullable();
            $table->longText('employee_request')->nullable();
            $table->longText('next_mpr_goal')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mprs');
    }
};
