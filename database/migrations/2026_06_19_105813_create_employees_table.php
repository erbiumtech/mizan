<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmployeesTable extends Migration
{
    public function up()
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Link to User
            $table->string('employee_id')->unique();
            $table->string('phone')->nullable();
             $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->date('date_of_joining')->nullable();
            $table->string('nic')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('iban_no')->nullable();
            $table->enum('gender', ['Male', 'Female']);
            $table->boolean('is_active')->default(true); // Status
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('employees');
    }
}
