<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person the company pays for work who is not on the payroll.
 *
 * They are already payable — a Beneficiary carries bank details and a payment type,
 * and Payment already pays one — so this adds the two things that were missing:
 * knowing which payees are people doing work rather than landlords and utilities, and
 * being able to say what each was paid over a year.
 *
 * No withholding and no payslip. A contractor invoices, and what they owe on it is
 * theirs to settle; treating them as staff is the mistake this is here to avoid, not a
 * feature of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->boolean('is_contractor')->default(false)->after('is_active')
                ->comment('A person paid for work, as opposed to a landlord, utility or supplier');

            $table->string('engagement')->nullable()->after('is_contractor')
                ->comment('What they do — for the year-end summary');

            $table->date('engaged_on')->nullable()->after('engagement');
            $table->date('engagement_ended_on')->nullable()->after('engaged_on');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['is_contractor', 'engagement', 'engaged_on', 'engagement_ended_on']);
        });
    }
};
