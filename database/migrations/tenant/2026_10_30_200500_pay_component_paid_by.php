<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who pays a pay component: the employee (taken off their pay) or the employer (a cost on top of it).
 *
 * The employer's statutory contributions — EOBI, provincial social security, the provident-fund match —
 * were deductions like any other, so putting one on a package took it out of the employee's net pay. An
 * employer-paid component never reduces net pay; it posts as an expense against the payable the component
 * already names. The expense account is chosen on the component the same two ways the payable is: an
 * account, or a key into the payroll account mapping.
 *
 * Backfilled here, once, for the three employer components StatutoryComponentSeeder ships, with the
 * mapping's staff-cost line as their expense — a default a company changes on the component's form.
 */
return new class extends Migration
{
    private const EMPLOYER_COMPONENTS = ['eobi_employer', 'social_security_employer', 'provident_fund_employer'];

    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table): void {
            $table->string('paid_by', 16)->default('employee')->after('kind');
            $table->foreignId('expense_account_id')->nullable()->after('account_id')->constrained('accounts')->nullOnDelete();
            $table->string('expense_account_key', 64)->nullable()->after('expense_account_id');
        });

        $this->backfill();
    }

    /** Public so the backfill can be asserted on rows that exist — at migrate time in a test there are none. */
    public function backfill(): void
    {
        DB::table('pay_components')
            ->whereIn('code', self::EMPLOYER_COMPONENTS)
            ->update(['paid_by' => 'employer']);

        DB::table('pay_components')
            ->whereIn('code', self::EMPLOYER_COMPONENTS)
            ->whereNull('expense_account_id')
            ->whereNull('expense_account_key')
            ->update(['expense_account_key' => 'basic_wage']);
    }

    public function down(): void
    {
        Schema::table('pay_components', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropColumn(['paid_by', 'expense_account_key']);
        });
    }
};
