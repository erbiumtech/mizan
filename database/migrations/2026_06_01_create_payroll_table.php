<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /** References Data */
        Schema::create('payroll_statuses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code', 255);
            $table->boolean('is_locked')->default(true);
            $table->timestamps();
        });

        Schema::create('payslip_statuses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code', 255);
            $table->boolean('is_locked')->default(true);
            $table->timestamps();
        });

        Schema::create('deduction_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code', 255);
            $table->boolean('is_locked')->default(true);
            $table->timestamps();
        });

        Schema::create('earning_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code', 255);
            $table->boolean('is_locked')->default(true);
            $table->timestamps();
        });

        Schema::create('payrolls', function (Blueprint $table) {
            $table->increments('id');
            $table->string('hashslug')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('month')->comment('Payroll for Month');
            $table->unsignedSmallInteger('year')->comment('Payroll for Year');
            $table->date('date')->comment('Pay Date / Pay Day');

            $table->boolean('is_locked')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->increments('id');
            $table->string('hashslug')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('payroll_id');
            $table->foreign('payroll_id')->references('id')->on('payrolls')->cascadeOnDelete();

            $table->decimal('total_earning', 15, 2)->default(0)->comment('Total Earning');
            $table->decimal('total_deduction', 15, 2)->default(0)->comment('Total Deduction');

            $table->decimal('basic_salary', 15, 2)->default(0)->comment('Total Basic Salary');
            $table->decimal('gross_salary', 15, 2)->default(0)->comment('Total Gross Salary');
            $table->decimal('net_salary', 15, 2)->default(0)->comment('Total Net Salary');

            $table->boolean('is_verified')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_locked')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('earnings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('hashslug')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('payroll_id');
            $table->unsignedInteger('payslip_id');
            $table->unsignedInteger('earning_type_id');
            $table->foreign('payroll_id')->references('id')->on('payrolls')->cascadeOnDelete();
            $table->foreign('payslip_id')->references('id')->on('payslips')->cascadeOnDelete();
            $table->foreign('earning_type_id')->references('id')->on('earning_types');

            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('deductions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('hashslug')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('payroll_id');
            $table->unsignedInteger('payslip_id');
            $table->unsignedInteger('deduction_type_id');
            $table->foreign('payroll_id')->references('id')->on('payrolls')->cascadeOnDelete();
            $table->foreign('payslip_id')->references('id')->on('payslips')->cascadeOnDelete();
            $table->foreign('deduction_type_id')->references('id')->on('deduction_types');

            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('deductions');
        Schema::dropIfExists('earnings');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('earning_types');
        Schema::dropIfExists('deduction_types');
        Schema::dropIfExists('payslip_statuses');
        Schema::dropIfExists('payroll_statuses');
        Schema::enableForeignKeyConstraints();
    }
};
