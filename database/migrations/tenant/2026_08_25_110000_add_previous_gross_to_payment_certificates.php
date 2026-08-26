<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gross figure a certificate nets against — `docs/construction-management-plan.md` §10.
 *
 * **The bug this closes.** A certificate's deduction rows are *movements* (this period's retention, this
 * period's advance recovery), but the row that netted off earlier certificates used `previously_certified`,
 * which is **net cash** — the prior certificates' `current_due`, already reduced by their own retention.
 * Subtracting it therefore handed the earlier retention back, and nothing put it back again.
 *
 *   IPC-1   380,000 gross − 38,000 retention                    = 342,000 paid
 *   IPC-2   600,000 gross −  22,000 retention − 342,000 (net)   = 236,000 paid   ← 38,000 too much
 *
 * Over the two certificates 578,000 was certified against 600,000 of work, so only 22,000 was actually
 * withheld — while the retention register said 60,000. Two of the company's own records disagreeing, with
 * neither of them wrong on its own terms.
 *
 * **Why it survived.** On a first certificate the movement *is* the cumulative figure, so the two agree and
 * the error cannot appear. It needs a second *live* certificate — and the only two-certificate tests void
 * the first, which zeroes `previously_certified` and makes the second behave like a first.
 *
 * **The fix, and why a new column rather than a redefinition.** Movement rows net against the previous
 * *gross*; the printed G702 line 7 wants the previous *net*. Both figures are genuinely needed, so both are
 * stored under their own names. Redefining `previously_certified` to mean gross would have left a column
 * whose name says one thing and whose contents say another — and the certificate PDF reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('construction_payment_certificates', function (Blueprint $table) {
            /*
             * Gross certified by every live certificate before this one — a frozen snapshot, like every
             * other figure on this table.
             *
             * Nullable with no default rather than 0, so a row written before this migration is
             * distinguishable from one whose predecessors genuinely certified nothing. The backfill below
             * fills the ones that can be derived; anything left null is a certificate issued under the old
             * arithmetic and should be read as such.
             */
            $table->decimal('previous_gross_value_to_date', 15, 2)->nullable()->after('previously_certified');
        });

        /*
         * Backfill from the certificates themselves.
         *
         * Only touches rows that can be derived without ambiguity: a first certificate has nothing before
         * it, so its previous gross is zero. Later ones are left null deliberately — recomputing them would
         * restate the arithmetic of certificates that have already been issued, countersigned and paid, and
         * §3.4's whole argument is that silently changing a signed-off figure is worse than leaving a
         * visible gap.
         */
        Schema::hasTable('construction_payment_certificates')
            && \Illuminate\Support\Facades\DB::connection(\App\Support\TenantTransaction::connectionName())
                ->table('construction_payment_certificates')
                ->where('sequence', 1)
                ->update(['previous_gross_value_to_date' => 0]);
    }

    public function down(): void
    {
        Schema::table('construction_payment_certificates', function (Blueprint $table) {
            $table->dropColumn('previous_gross_value_to_date');
        });
    }
};
