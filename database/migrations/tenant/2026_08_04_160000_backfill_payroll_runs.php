<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the payslips that already exist the run they belong to.
 *
 * One run per month and fiscal year, all left open. Locking is a decision somebody
 * makes about a month, and a migration is not somebody — signing off eleven months of
 * history on a company's behalf because the schema now allows it would be exactly the
 * wrong reading of what this feature is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        $months = DB::table('payslips')
            ->select('month', 'fiscal_year_id')
            ->whereNotNull('fiscal_year_id')
            ->distinct()
            ->get();

        foreach ($months as $month) {
            $runId = DB::table('payroll_runs')
                ->where('month', $month->month)
                ->where('fiscal_year_id', $month->fiscal_year_id)
                ->value('id');

            $runId ??= DB::table('payroll_runs')->insertGetId([
                'month' => $month->month,
                'fiscal_year_id' => $month->fiscal_year_id,
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('payslips')
                ->where('month', $month->month)
                ->where('fiscal_year_id', $month->fiscal_year_id)
                ->whereNull('payroll_run_id')
                ->update(['payroll_run_id' => $runId]);
        }
    }

    public function down(): void
    {
        DB::table('payslips')->update(['payroll_run_id' => null]);
        DB::table('payroll_runs')->delete();
    }
};
