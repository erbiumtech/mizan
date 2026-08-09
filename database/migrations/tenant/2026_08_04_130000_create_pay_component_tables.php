<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay as data instead of columns.
 *
 * Adding one allowance today means editing thirteen files — the settings form and
 * table, the payslip form and table, both models, PayslipService,
 * PayrollPostingService, the billing statement, two seeders and the payslip PDF —
 * because every part of pay is a column and every column is named in every one of
 * them. Two symptoms are already in the codebase: the client statement keeps a
 * hand-maintained column map with an "Other" bucket invented to catch gross the
 * named columns cannot explain, and PayrollAccounts maps each component to an
 * account by a hard-coded key.
 *
 * Three tables:
 *
 *  - `pay_components` — what parts of pay exist, what kind each is, and where it
 *    posts. One row per allowance or deduction.
 *  - `employee_setting_components` — what an employee is due, hanging off the
 *    existing EmployeeSetting so it inherits the date ranges payroll already
 *    versions packages by.
 *  - `payslip_components` — what a payslip actually paid, which is a different fact
 *    from what somebody is due and has to be recorded separately or a corrected
 *    package rewrites history.
 *
 * The existing columns stay. This release backfills and cross-checks against them;
 * dropping them is a later one, once nothing reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Stable identifier, e.g. fuel_allowance');
            $table->string('label');
            $table->enum('kind', ['earning', 'deduction']);

            // Which PayrollAccounts key this posts through, so a new component can
            // reuse the mapping the shipped ones already use rather than inventing a
            // second way to find an account.
            $table->string('account_key')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->boolean('is_taxable')->default(true)
                ->comment('Earnings only: whether it counts toward taxable income');

            // True for the parts of pay that still live in columns. They are listed
            // so the set is complete and reportable, but the calculation reads their
            // column, not their component amount, until a later release moves it.
            $table->boolean('is_column_backed')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['kind', 'is_active', 'sort']);
        });

        Schema::create('employee_setting_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_setting_id')->constrained('employee_settings')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            // One amount per component per package version.
            $table->unique(['employee_setting_id', 'pay_component_id'], 'unique_setting_component');
        });

        Schema::create('payslip_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['payslip_id', 'pay_component_id'], 'unique_payslip_component');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_components');
        Schema::dropIfExists('employee_setting_components');
        Schema::dropIfExists('pay_components');
    }
};
