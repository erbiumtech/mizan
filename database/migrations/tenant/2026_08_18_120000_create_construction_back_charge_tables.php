<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back-charges — `docs/construction-management-plan.md` §12.
 *
 * A back-charge is work the main contractor did that the subcontract says the subcontractor should have done: the
 * cleanup nobody came back for, the rework after an NCR, the crane hours attending somebody else's delivery. The money
 * is recovered by **deducting it from the next certificate**, which is why this table's end state is
 * `applied_certificate_id` and a `back_charge` row in `construction_certificate_deductions`.
 *
 * **`notified_on` matters more than it looks, and §12 says why:** almost every subcontract requires notice before a
 * back-charge may be deducted, and "an incurred-but-unnotified back-charge is money the company will not get and does
 * not yet know it has lost". So notice is a state this table can be *queried on* rather than a habit somebody has, and
 * the un-notified list is the register's most useful screen.
 *
 * **Nothing here applies itself.** §16.5 settles the precedent for NCRs and it holds identically here: the back-charge
 * *proposes*, and the certification service offers it as a row a human confirms and signs for. "A deduction appearing
 * on a certificate that nobody decided on is the fastest available route to a dispute, and it will be the contractor's
 * dispute, because the client's copy has already left the building."
 *
 * **Draft computes, notice freezes** — the same rule the certificate keeps (§10). `total_amount` is stored rather than
 * derived because the notified figure is the figure the subcontractor was told, and a total that moved with a later
 * edit to the markup would make the notice a document nobody could rely on. While the charge is a draft it is
 * recomputed on every write; once notice is served it is fixed, and the way to change it is `agreed_amount` — a
 * negotiated number, with the original still on the row beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_back_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contract_id')->constrained('construction_contracts')->cascadeOnDelete();

            /*
             * The job as well as the contract, denormalised on purpose: a back-charge register filtered by job is what
             * a project manager asks for, and reaching it through the contract on every row is a join this table can
             * spare. The contract's own `job_id` remains the authority — this is a copy for reading.
             */
            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();

            // `BC-1` upward per contract, following the contract and certificate series. A gap in the series is a
            // question at final account, and "withdrawn on the 14th" is an answer where a missing number is not.
            $table->string('reference');

            $table->enum('kind', [
                'materials', 'labour', 'plant', 'cleanup', 'rework',
                'damage', 'ncr_rectification', 'schedule_recovery', 'attendance', 'welfare',
            ]);

            /*
             * What the charge is evidence of — an NCR, a punch item, a diary entry. Unconstrained, because §16's tables
             * do not exist yet and §18 keeps this module sellable without them: the morph stays null and the
             * description carries the fact. `back_charges.source_type` is named in §18.2's list of plain-column morphs
             * that `enforceMorphMap()` cannot cover, so the model writes it through `ModuleMap::alias()`.
             */
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('description');
            $table->date('incurred_on')->nullable()->comment('When the company did the work it is charging back');

            /*
             * The cost, the markup on it, and the total that is notified.
             *
             * Markup is separate from the cost because it is the part that gets argued about: a subcontractor will
             * accept an invoice for a skip and contest fifteen per cent on top of it, and a single total figure gives
             * the argument nowhere to land. Most subcontracts allow a stated percentage for supervision and overhead.
             */
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('markup_percent', 8, 4)->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->enum('status', ['draft', 'notified', 'disputed', 'agreed', 'applied', 'withdrawn'])
                ->default('draft');

            /*
             * **Notice.** The date, who served it, and the notice document itself in §15's register rather than as a
             * blob here. Without a date this charge cannot be applied, which is the rule the whole table exists for.
             */
            $table->date('notified_on')->nullable();
            $table->unsignedBigInteger('notified_by')->nullable();
            $table->foreignId('notice_document_id')->nullable()
                ->constrained('construction_documents')->nullOnDelete();

            // The dispute. Recorded rather than resolved: a contested back-charge is a fact about the account, and a
            // register that only held the ones nobody argued with would understate the exposure every time.
            $table->date('disputed_on')->nullable();
            $table->text('dispute_reason')->nullable();

            /*
             * The settlement. `agreed_amount` is separate from `total_amount` so both survive: the figure notified and
             * the figure settled are different facts, and overwriting the first loses the concession.
             */
            $table->date('agreed_on')->nullable();
            $table->decimal('agreed_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('agreed_by')->nullable();

            /*
             * Where the money actually came off. Nullable until somebody applies it, and null is the interesting
             * state — an agreed back-charge with no certificate is a recovery the company has decided on and not
             * taken.
             */
            $table->foreignId('applied_certificate_id')->nullable()
                ->constrained('construction_payment_certificates')->nullOnDelete();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->date('applied_on')->nullable();

            // Withdrawing needs a reason for the same reason voiding a certificate does: somebody outside this company
            // has been told about this charge.
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->text('withdrawal_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
            $table->index(['job_id', 'status']);
            // The exposure query: incurred, not yet notified. Indexed because it is the one somebody runs weekly.
            $table->index(['status', 'notified_on']);
            $table->unique(['contract_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_back_charges');
    }
};
