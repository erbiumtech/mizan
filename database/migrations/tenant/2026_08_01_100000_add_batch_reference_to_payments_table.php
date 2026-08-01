<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which batch a payment went out in, and when.
 *
 * A month's salaries do not necessarily leave in one file: some employees have
 * accepted their payslip and some have not, so the accepted ones go now and the
 * rest follow. `status` already said whether a payment had been exported, which
 * is what keeps a released payment out of the next batch — these two columns say
 * *which* release it belonged to, so a file that reached the bank can be tied
 * back to the rows that made it.
 *
 * Indexed on the reference because looking a batch up by the number written on
 * the bank's paperwork is the whole reason it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('batch_reference')
                ->nullable()
                ->after('status')
                ->comment('e.g. SAL-2026-07-B1. Null until the payment is released');

            $table->timestamp('released_at')
                ->nullable()
                ->after('batch_reference');

            $table->index('batch_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['batch_reference']);
            $table->dropColumn(['batch_reference', 'released_at']);
        });
    }
};
