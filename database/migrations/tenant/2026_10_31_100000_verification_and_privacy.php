<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verification and privacy — docs/saas-launch-plan.md §14.8.
 *
 * Three things: a document can now be refused as well as accepted, a phone number
 * or an address can be proved, and an employee can be erased without taking the
 * payslips with them.
 *
 * `employee_verifications` records the proof of a *value*, not of a person — the
 * number or the address is stored beside the verification, so somebody who changes
 * their phone is unverified again without anything having to notice the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table): void {
            // The other half of `verified_at`, which has existed since August with
            // nothing writing it: a document can be refused, and the reason is for the
            // employee who has to send a better scan.
            $table->timestamp('rejected_at')->nullable()->after('verified_at');
            $table->string('rejection_reason')->nullable()->after('rejected_at');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejection_reason');
        });

        Schema::create('employee_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('kind', 24);
            // What was proved. Normalised — an E.164 number, a flattened address — so a
            // comparison against the record today is a string comparison.
            $table->string('target');
            // The code itself is never stored, for the reason a password is not.
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'kind']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            // Erased, not deleted. A payslip and a journal entry are kept for the
            // statutory period whatever anybody asks, so erasure empties the person out
            // of the record and leaves the record where it is.
            $table->timestamp('anonymised_at')->nullable()->after('left_on');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('anonymised_at'));
        Schema::dropIfExists('employee_verifications');
        Schema::table('employee_documents', fn (Blueprint $table) => $table->dropColumn(['rejected_at', 'rejection_reason', 'rejected_by']));
    }
};
