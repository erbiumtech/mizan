<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manual correction to an entitlement — rows, not two columns on the
 * entitlement itself.
 *
 * An earlier draft of docs/hrms-plan.md §4.1 had `adjustment_days` and
 * `adjustment_reason` on leave_entitlements, and that fails the argument sitting
 * one paragraph above it: a goodwill grant in March and a correction in August
 * cannot both exist in two columns, so the second silently overwrites the first
 * and takes its reason with it. The balance is then a number nobody can explain,
 * which is exactly the state computing it rather than storing it was meant to
 * avoid.
 *
 * `made_by` and `made_at` matter as much as `days`. "Who gave me these three days,
 * and when" is the first question every disputed balance opens with, and an
 * overwritten column can never answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_entitlement_id')->constrained('leave_entitlements')->cascadeOnDelete();

            // Signed: a correction takes days away as readily as a goodwill grant
            // adds them, and two columns for the two directions would need a rule
            // about what a row with both means.
            $table->decimal('days', 5, 1)
                ->comment('Signed — negative is a correction downwards');

            // Required, unlike most notes fields here. An adjustment with no
            // reason is the thing this table exists to prevent: a balance that
            // moved and nobody can say why.
            $table->string('reason');

            // A soft user reference, the shape invoice_events.caused_by already
            // uses: users are a landlord table, so a constrained foreign key
            // would not resolve from a tenant connection.
            $table->foreignId('made_by')->nullable()->index()
                ->comment('Soft reference to the landlord users table, like invoice_events.caused_by');

            $table->timestamp('made_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_adjustments');
    }
};
