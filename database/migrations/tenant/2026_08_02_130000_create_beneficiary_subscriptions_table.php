<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing monthly payment to a beneficiary — rent, the internet line, the
 * cleaner — raised as a draft payment each month by the scheduler rather than
 * typed in again.
 *
 * The subscription is the agreement; the payments are what it produced. Keeping
 * them apart is what lets a month be regenerated, an amount be changed from a
 * date without rewriting history, and a run be proved idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiary_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained('beneficiaries')->cascadeOnDelete();

            // Both optional: the beneficiary already carries a transaction type and
            // the type carries a default bank account. Stated here only when this
            // particular subscription differs.
            $table->foreignId('transaction_type_id')->nullable()->constrained('transaction_types')->nullOnDelete();
            $table->foreignId('company_bank_account_id')->nullable()->constrained('company_bank_accounts')->nullOnDelete();

            $table->string('description')->comment('Payment Details 1 in the bank file, e.g. "House rent"');
            $table->decimal('amount', 15, 2);

            $table->unsignedTinyInteger('due_day')->default(1)
                ->comment('Day of the month the payment is dated; clamped to the length of short months');

            $table->date('starts_on');
            $table->date('ends_on')->nullable()->comment('Last month billed; open-ended when null');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_on']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('beneficiary_subscription_id')->nullable()->after('payslip_id')
                ->constrained('beneficiary_subscriptions')->nullOnDelete()
                ->comment('Set when raised from a subscription');

            $table->date('period')->nullable()->after('beneficiary_subscription_id')
                ->comment('First day of the month a recurring payment covers');

            // One payment per subscription per month, enforced rather than hoped
            // for: the scheduler is a cron job, and a rerun — a retry, a manual
            // catch-up, two workers — must not raise the rent twice.
            $table->unique(['beneficiary_subscription_id', 'period'], 'unique_subscription_period');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('unique_subscription_period');
            $table->dropConstrainedForeignId('beneficiary_subscription_id');
            $table->dropColumn('period');
        });

        Schema::dropIfExists('beneficiary_subscriptions');
    }
};
