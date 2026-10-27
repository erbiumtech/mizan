<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-payslip typed overrides — docs/payslip-component-input-plan.md Phase 2.
 *
 * Where a month's typed input will live once it moves off the eleven payslip columns: a clerk's "5000
 * bonus this month" becomes a row here against the bonus component, read by the calculation before it
 * computes. The table starts empty, so nothing reads from it yet; `PayslipComponentRecorder` still writes
 * the output projection into `payslip_components`, which this is deliberately NOT — input, not output.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_component_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            // One typed amount per component per payslip.
            $table->unique(['payslip_id', 'pay_component_id'], 'unique_payslip_component_input');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_component_inputs');
    }
};
