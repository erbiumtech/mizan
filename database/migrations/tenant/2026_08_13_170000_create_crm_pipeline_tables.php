<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: the pipeline, and the deals in it.
 *
 * **Stages are rows, not an enum.** Every company renames them, and a config array means a
 * rename is a deploy. `probability_pct` on the stage gives a weighted forecast without
 * asking a salesperson to guess twice; `is_won` / `is_lost` mark the terminal stages so
 * reports do not pattern-match on names; `rot_after_days` powers the only pipeline report
 * that changes behaviour — deals that have not moved.
 *
 * **`opportunities` has nullable `lead_id` and nullable `contact_id` with exactly one
 * set**, asserted in the model and tested. That is docs/crms-plan.md §1's whole
 * architecture: a prospect is CRM's and a customer is Invoicing's, and a deal belongs to
 * one or the other. A repeat deal against an existing customer has `contact_id`; new
 * business has `lead_id` until it converts, and conversion fills `contact_id` while
 * KEEPING `lead_id` as the origin — "where did this customer come from" is a lead-source
 * question asked years later.
 *
 * Currency is stored per opportunity WITH the rate used, because a forecast in mixed
 * currencies has to be summed at some rate, and a rate that moves silently rewrites last
 * quarter's forecast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);

            // The company's guess, applied to every deal in the stage. Fine at this scale;
            // §13 warns against letting it become a forecasting claim.
            $table->unsignedTinyInteger('probability_pct')->default(0);

            // Terminal markers, so reports never pattern-match on a renamed stage.
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);

            // Days without movement before a deal is reported as rotting. Null means this
            // stage never rots — appropriate for a long qualification stage, and for the
            // terminal ones.
            $table->unsignedSmallInteger('rot_after_days')->nullable();

            $table->timestamps();

            $table->index(['pipeline_id', 'sort']);
        });

        Schema::create('lost_reasons', function (Blueprint $table) {
            $table->id();
            // A table rather than a free-text box because win/loss BY REASON is the report
            // §8 says is worth more than the forecast, and free text gives you "price",
            // "Price" and "too expensive" as three reasons with three rates.
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            $table->foreignId('pipeline_id')->constrained('pipelines')->restrictOnDelete();
            $table->foreignId('pipeline_stage_id')->constrained('pipeline_stages')->restrictOnDelete();

            // Exactly one of these two. Not expressible as a column constraint portably,
            // so Opportunity::booted() asserts it — the way ContactPerson asserts one
            // primary per contact rather than hoping.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Guarded on `employees`, exactly as leads are: EmployeeAccess scoping applies
            // unchanged, and without the module the field is never offered.
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency_code', 3)->nullable()
                ->comment('Null means the company base currency');

            // The rate USED, stored. A forecast that read live rates would silently
            // rewrite last quarter — §13 is explicit that anyone "fixing" this breaks it.
            $table->decimal('exchange_rate', 15, 6)->nullable();

            // Copied from the stage when the deal enters it, and overridable per deal: a
            // salesperson who knows this one is 90% should be able to say so without
            // moving every other deal in the stage.
            $table->unsignedTinyInteger('probability_pct')->nullable();

            $table->date('expected_close_on')->nullable();
            $table->date('closed_on')->nullable();

            $table->string('outcome')->nullable()->comment('won|lost — null while open');
            $table->foreignId('lost_reason_id')->nullable()->constrained('lost_reasons')->nullOnDelete();

            // Guarded hand-offs, both nullable and both filled by a human action (phase 6).
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->timestamps();

            // The board reads: a pipeline's open deals by stage, and an owner's list.
            $table->index(['pipeline_stage_id', 'outcome']);
            $table->index(['owner_employee_id', 'outcome']);
            $table->index('expected_close_on');
        });

        Schema::create('opportunity_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            // Null on the first row: a deal enters its first stage from nowhere.
            $table->foreignId('from_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->constrained('pipeline_stages')->restrictOnDelete();

            $table->foreignId('moved_by')->nullable()->index();
            $table->timestamp('moved_at');

            // How long it sat in the stage it just left. Computed on the move and stored,
            // because deriving it later means reading the whole history every time — and
            // a move BACK must be recorded rather than overwriting, which is what makes
            // the sum of these meaningful.
            $table->unsignedSmallInteger('days_in_stage')->nullable();

            $table->timestamps();

            $table->index(['opportunity_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_stage_history');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('lost_reasons');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipelines');
    }
};
