<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demand document — `docs/construction-management-plan.md` §5.
 *
 * "Material requisition — new `construction_requisitions`, **nothing here has a demand document**." That absence is
 * the whole reason this table exists: without it, the first record of a need is the order placed to satisfy it, so
 * *what site asked for and nobody has ordered yet* is a question with no answer, and the buyer's queue is somebody's
 * inbox.
 *
 * Two decisions carry it.
 *
 * **A requisition commits nothing.** It is a request, and money is committed when an order is *issued* (§5). So
 * there is no relief mechanism here and no figure on the cost report — what a requisition has instead is an
 * outstanding quantity, derived as `requested − ordered` from the commitment lines that name it.
 *
 * **The cost code is nullable here and required at order time.** Site asks for forty tonnes of rebar; the buyer
 * decides which code carries it. Demanding the code on the request would either block the request or teach site
 * staff to pick any code that lets the form save, and the second is worse than the first because it looks like
 * data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->comment('REQ-2026-0142');

            // Always for a job: a requisition with no job is a stores request, which is §6's document rather than
            // this one.
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();
            // Where it is wanted — construction owns its locations (§16.5), so this is a real reference.
            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();

            $table->enum('status', [
                'draft', 'submitted', 'approved', 'rejected', 'partially_ordered', 'ordered', 'cancelled',
            ])->default('draft');

            /*
             * When it is needed on site, which is what orders the buyer's queue. Not a priority flag: "urgent"
             * means whatever the person ticking it wants it to mean, and a date is a fact somebody can plan
             * against.
             */
            $table->date('required_by')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->date('requested_on')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('number');
            $table->index(['job_id', 'status']);
            $table->index('required_by');
        });

        Schema::create('construction_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('construction_requisitions')->cascadeOnDelete();

            // Nullable, deliberately — see the class docblock. The buyer supplies it when the order is raised, and
            // `RequisitionService` refuses to order a line without one.
            $table->foreignId('cost_code_id')->nullable()->constrained('construction_cost_codes')->nullOnDelete();

            // Guarded on Inventory, like the commitment line's: most site requests are a description and a
            // quantity, which is how they are written on paper.
            $table->unsignedBigInteger('product_id')->nullable();

            $table->text('description');
            $table->decimal('quantity', 14, 4);
            $table->string('unit_of_measure', 16)->nullable();

            /*
             * An **estimate**, and named as one. It is what site thinks it will cost, useful for the approval
             * decision and for nothing else: the committed figure comes from the order, at the rate the supplier
             * actually quoted.
             */
            $table->decimal('estimated_rate', 14, 4)->nullable();
            $table->decimal('estimated_amount', 15, 2)->nullable();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('requisition_id');
            $table->index('cost_code_id');
        });

        /*
         * The link that makes "requested and not yet ordered" provable.
         *
         * On the **commitment** line rather than the requisition line, because one request is routinely satisfied by
         * more than one order: forty tonnes ordered as twenty now and twenty in March is ordinary, and a single
         * column on the requisition line could only remember one of them. Many order lines to one requisition line,
         * so the outstanding quantity is `requested − Σ ordered`, computed the same way open commitment is.
         *
         * Nullable, because most orders in most companies are raised without a requisition at all.
         */
        Schema::table('construction_commitment_lines', function (Blueprint $table) {
            $table->foreignId('requisition_line_id')->nullable()->after('commitment_id')
                ->constrained('construction_requisition_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('construction_commitment_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_line_id');
        });

        Schema::dropIfExists('construction_requisition_lines');
        Schema::dropIfExists('construction_requisitions');
    }
};
