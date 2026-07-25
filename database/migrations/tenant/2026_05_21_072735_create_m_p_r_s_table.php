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

            $table->foreignId('user_id')->index(); // soft ref -> landlord users

            $table->date('mpr_date')->nullable();

            $table->longText('feedback')->nullable();
            $table->longText('topics_scope')->nullable();
            $table->longText('recent_module')->nullable();
            $table->longText('employee_request')->nullable();
            $table->longText('next_mpr_goal')->nullable();
            $table->text('current_month_learning')->nullable();
            $table->string('pdf_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mprs');
    }
};
