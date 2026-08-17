<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budget, forecast and earned value — `docs/construction-management-plan.md` §3.5 and §14.
 *
 * **Budgets are versioned because earned value needs a baseline that does not move and a cost report needs a
 * budget that does.** Without that split, cost variance is measured against a number somebody edited last
 * Tuesday, schedule variance is meaningless, and the variance report answers a question nobody asked.
 *
 * **A forecast is a snapshot, not a mutable row.** The whole value of forecasting is comparing last month's
 * estimate at completion with this month's — *we said 4.2 in March and 4.9 in April; what moved* — and a single
 * mutable row destroys the only report that makes forecasting worth doing.
 *
 * **Earned value is measured physically and frozen.** §14 is emphatic: if earned value is derived from cost then
 * it equals actual cost, the cost performance index is exactly 1.00, and every job reads *precisely on budget*
 * forever. "Every number is present and none of them means anything" — a silent failure of the purest kind. So
 * `earned_value` is computed at measurement time from the budget as it stood then, and stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_budget_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('name')->comment('Tender, Contract award, Rev 3 post VO-12');
            $table->enum('kind', ['estimate', 'original_budget', 'revision'])->default('estimate');
            $table->enum('status', ['draft', 'approved', 'superseded'])->default('draft');

            /*
             * Exactly one of each per job, enforced in the model the way `FiscalYear::booted()` enforces a
             * single active year — and for the same reason: everything asks the same way, so a second one does
             * not read as an error anywhere. It reads as the wrong budget.
             *
             * They are separate flags because they answer different questions. The **baseline** is what earned
             * value measures against and must not move once work has been claimed against it. The **current**
             * budget is what the cost report compares actuals to and moves every time a variation is approved.
             * On a job with no variations they are the same version; the moment one is approved they diverge,
             * and conflating them makes every schedule variance meaningless.
             */
            $table->boolean('is_baseline')->default(false);
            $table->boolean('is_current')->default(false);

            $table->date('effective_from')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'version_no']);
            $table->index(['job_id', 'is_current']);
            $table->index(['job_id', 'is_baseline']);
        });

        Schema::create('construction_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_version_id')->constrained('construction_budget_versions')->cascadeOnDelete();
            // Denormalised deliberately: the cost report joins budget to actual per job per code, and that is
            // the hot query on the page this module is bought for.
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();
            // Snapshotted like the cost entry's, and for the same reason: a recoded library must not restate an
            // approved budget's labour/material split.
            $table->enum('cost_type', ['labour', 'material', 'plant', 'subcontract', 'other']);

            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('unit_of_measure', 16)->nullable();
            $table->decimal('unit_rate', 14, 4)->nullable();
            $table->decimal('amount', 15, 2);

            /*
             * **Nullable, and the null is the point.** A time-phased line says which month its budget belongs
             * to, which is what makes planned value — and therefore schedule variance — computable. A null means
             * the budget was never phased, and §14 requires the report to say *"schedule performance
             * unavailable: the budget is not time-phased"* rather than showing a zero that means "no data".
             */
            $table->date('period_start')->nullable();

            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'cost_code_id']);
            $table->index(['budget_version_id', 'period_start']);
        });

        /*
         * §14. The control account is the intersection of job, WBS node and cost code — where budget, scope and
         * actuals meet, and what makes earned value computable rather than decorative.
         */
        Schema::create('construction_progress_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();
            $table->date('period_start');

            $table->enum('method', [
                'units_completed', 'incremental_milestone', 'weighted_steps',
                'percent_complete', 'level_of_effort',
            ])->default('percent_complete');

            $table->decimal('quantity_completed', 14, 4)->nullable();
            $table->decimal('quantity_total', 14, 4)->nullable();
            $table->decimal('percent_complete', 7, 4)->default(0)->comment('0–100');

            /*
             * **Frozen at measurement time.** The budget at completion for this element multiplied by percent
             * complete, computed when the measurement is taken and stored — because the budget moves when a
             * revision is approved and last month's earned value must not.
             *
             * And **never derived from actual cost**: that is what makes CPI exactly 1.00 for every job forever.
             */
            $table->decimal('earned_value', 15, 2)->default(0);
            // What it was measured against, kept so a later reader can see why the figure is what it is.
            $table->decimal('budget_at_completion', 15, 2)->nullable();
            $table->foreignId('measured_against_version_id')->nullable()
                ->constrained('construction_budget_versions')->nullOnDelete();

            $table->unsignedBigInteger('measured_by')->nullable();
            $table->date('measured_on')->nullable();
            // A locked measurement is evidence: it fed a certificate and an earned-value report.
            $table->timestamp('locked_at')->nullable();
            $table->string('notes')->nullable();

            $table->timestamps();

            // One measurement per control account per period: two would double-count earned value, which reads
            // as a job ahead of schedule.
            $table->unique(['job_id', 'wbs_node_id', 'cost_code_id', 'period_start'], 'cpm_control_account_period');
            $table->index(['job_id', 'period_start']);
        });

        Schema::create('construction_forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->date('period_start');
            $table->string('name')->nullable();
            $table->enum('status', ['draft', 'issued'])->default('draft');
            // Which budget version it was forecast against, so a comparison between two runs can say whether
            // the budget moved or the forecast did.
            $table->foreignId('budget_version_id')->nullable()
                ->constrained('construction_budget_versions')->nullOnDelete();
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One issued forecast per job per month. A second would make "what did we say in March" ambiguous,
            // which is the one question forecasting exists to answer.
            $table->unique(['job_id', 'period_start']);
        });

        Schema::create('construction_forecast_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_run_id')->constrained('construction_forecast_runs')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            $table->foreignId('cost_code_id')->constrained('construction_cost_codes')->restrictOnDelete();

            $table->decimal('cost_to_complete', 15, 2)->default(0);

            /*
             * Which of §14's three standard methods produced the estimate at completion.
             *
             * **The report shows this per line**, because "the forecast went up" and "somebody changed the
             * method" are different facts and only one of them is news.
             */
            $table->enum('eac_method', ['manual_etc', 'remaining_budget', 'cpi_based'])->default('manual_etc');

            // Snapshotted so a run is readable years later without re-deriving anything from live data.
            $table->decimal('actual_to_date', 15, 2)->default(0);
            $table->decimal('accrued_to_date', 15, 2)->default(0);
            $table->decimal('budget_at_completion', 15, 2)->default(0);
            $table->decimal('forecast_final_cost', 15, 2)->default(0);

            /*
             * §3.5's committed-aware rule: **cost to complete may never be less than the open commitment on
             * that code.** A cost code with a purchase order worth more than its remaining budget is already
             * overspent, and a forecast saying otherwise is forecasting money that has already been promised
             * away. A lower figure is accepted **with a reason** and appears on a *forecasts below commitment*
             * exception report.
             *
             * Nullable until §5 exists — there are no commitments to compare against yet — and the columns are
             * here now so the rule has somewhere to live when procurement lands rather than being retrofitted.
             */
            $table->decimal('open_commitment', 15, 2)->nullable();
            $table->boolean('is_below_commitment')->default(false);
            $table->string('below_commitment_reason')->nullable();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['forecast_run_id', 'job_id', 'wbs_node_id', 'cost_code_id'], 'cfl_run_control_account');
            $table->index(['job_id', 'cost_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_forecast_lines');
        Schema::dropIfExists('construction_forecast_runs');
        Schema::dropIfExists('construction_progress_measurements');
        Schema::dropIfExists('construction_budget_lines');
        Schema::dropIfExists('construction_budget_versions');
    }
};
