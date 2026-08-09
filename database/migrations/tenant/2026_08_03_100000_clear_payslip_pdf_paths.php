<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The payslip PDF is rendered on request now, never stored, so the paths recorded
 * against payslips point at files nothing writes and nothing should read.
 *
 * Cleared rather than left in place: a path on a row reads as "this file is the
 * payslip", and those files were written once and reused, so several of them show
 * figures that have since been corrected. The column stays — dropping it is a
 * schema change this does not need — but it holds nothing to be misled by.
 *
 * The files themselves are left alone. They are no longer referenced, and
 * deleting a company's documents is not something a migration should decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payslips')->whereNotNull('pdf_path')->update(['pdf_path' => null]);
    }

    public function down(): void
    {
        // Irreversible by design: the previous values named files that no longer
        // agreed with the payslips they were attached to.
    }
};
