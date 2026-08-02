<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two things the ledger got away with because nothing had asked yet.
 *
 * Journal entries only carried a fiscal year when payroll created them — every
 * payment, petty cash voucher and invoice entry had none, two thirds of the
 * ledger on the company this was found on. Nothing filters by fiscal year today,
 * so it cost nothing; the day something does, those entries drop out of the
 * report with nothing to say they were ever there.
 *
 * And more than one year could be flagged active at once. Everything that asks
 * for the current year asks the same way — `where is_active, first()` — so a
 * second one does not read as an error anywhere, it reads as the wrong year.
 *
 * Both are repaired here from what the data already says: the entry's own date,
 * and the year that contains today. JournalEntryService and the FiscalYear model
 * keep them right from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillEntryFiscalYears();
        $this->keepOneYearActive();
    }

    /**
     * File each entry under the year its date falls in. Entries dated outside
     * every year keep their null, which is the honest answer — better no year
     * than the wrong one.
     */
    private function backfillEntryFiscalYears(): void
    {
        $years = DB::table('fiscal_years')
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->get(['id', 'start_date', 'end_date']);

        foreach ($years as $year) {
            DB::table('journal_entries')
                ->whereNull('fiscal_year_id')
                ->whereDate('entry_date', '>=', $year->start_date)
                ->whereDate('entry_date', '<=', $year->end_date)
                ->update(['fiscal_year_id' => $year->id]);
        }
    }

    /**
     * Keep the year containing today active where there is one, otherwise the
     * latest of those already flagged — never activating a year nobody had.
     */
    private function keepOneYearActive(): void
    {
        $active = DB::table('fiscal_years')->where('is_active', true)->orderByDesc('start_date')->get(['id', 'start_date', 'end_date']);

        if ($active->count() < 2) {
            return;
        }

        $today = now()->toDateString();

        $keep = $active->first(fn ($year): bool => $year->start_date <= $today && $year->end_date >= $today)
            ?? $active->first();

        DB::table('fiscal_years')
            ->where('is_active', true)
            ->where('id', '!=', $keep->id)
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        // Deliberately irreversible: the previous state was entries filed under no
        // year and two years both claiming to be current. There is nothing to
        // restore that anyone would want back.
    }
};
