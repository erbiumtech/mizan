<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The diary's last two children — `docs/construction-management-plan.md` §16.1.
 *
 * **Deliveries: the docket, not the valuation.** §16.1 asks for "docket number, received-by, purchase order reference,
 * contract item, and an `is_materials_on_site` flag" — and note what is absent from that list. No rate, no amount, no
 * cost code. That is deliberate and it is what keeps this table out of the way of §5's goods receipt, which is where a
 * delivery becomes money. A site record of what arrived and who signed for it is a different document from the priced
 * receipt accounts posts, and a company that keeps its commercial side elsewhere still needs the first one.
 *
 * **The `is_materials_on_site` decision, which is the one this sub-phase owed.** §16.1 calls the flag "the link that
 * makes G703's *materials presently stored* column defensible rather than asserted", and Phase 8c already computes that
 * figure from stock. Two sources for one number is the trap this suite refuses everywhere else, so:
 *
 *  - **The stock ledger is the authority for the figure.** The reason is arithmetic rather than preference: a flag can
 *    only ever accumulate. Nothing on a diary decreases when material is consumed, so a total of flagged deliveries
 *    overstates materials on site by exactly everything already issued — an error that grows every month with nothing
 *    saying so. `remaining_quantity` on the lots goes down on issue, which is why Phase 8c's figure is the one a
 *    certificate quotes.
 *  - **The flag is corroboration, and it answers two questions stock cannot.** Without the cost module there is no
 *    stock ledger at all, and the flagged dockets are the only record of what is standing on site — so a certificate
 *    says which source it used rather than showing nothing, because §18.1's failure is "a healthy figure hiding an
 *    absence". And *with* the cost module, a flagged delivery with no goods receipt behind it is material somebody
 *    signed for on site that the cost ledger has never seen: the docket that never reached accounts, which understates
 *    cost and overstates margin. Same shape as Phase 9b's unnotified event, and the same value.
 *
 * **Photos: their own table, and §16.1 is explicit about why.** "A site photo has no revision, no suitability code and
 * no approval, and forcing thousands of them into the ISO 19650 register creates junk containers and buries the
 * drawings the register exists for." So the register is not the store — but the handful that become as-built evidence
 * are promoted into it, which is what `promoted_document_id` records.
 *
 * The subject matters more than it looks: **`concealed_work` is the money one.** A photograph of reinforcement before
 * the pour is the only evidence it was ever there, and six months later that photograph is the difference between an
 * accepted element and one somebody wants opened up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_daily_log_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_log_id')->constrained('construction_daily_logs')->cascadeOnDelete();

            /*
             * The docket, **nullable on purpose**.
             *
             * It is the reference quoted back to a supplier and the first thing anybody asks for, so the table shows
             * its absence rather than hiding it. But material does arrive with no paperwork, and a delivery this
             * application refuses to record is a delivery recorded on the back of a drawing — which is worse than a
             * row saying "no docket" in plain sight.
             */
            $table->string('docket_number')->nullable();

            // Who delivered. A supplier is a Contact, which Invoicing owns, so the column stays null without that
            // module and the free-text name carries it — the same treatment the manpower line's company gets.
            $table->foreignId('supplier_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('supplier_label')->nullable();

            /*
             * The order it came against, as **text**.
             *
             * §5's purchase order lives in `construction_costing` and this module requires only `construction`, so
             * what a storeman writes on the docket — the order number as printed on it — is what is kept. The
             * structured link is `goods_receipt_id` below, and it appears when accounts receipt the delivery rather
             * than when site records it.
             */
            $table->string('order_reference')->nullable();

            // Which contract item it belongs to, unconstrained for the same licensing reason as §13's `contract_id`:
            // `construction_contract_items` is `construction_contracts`', and a diary must not need that module.
            $table->unsignedBigInteger('contract_item_id')->nullable();

            $table->text('description');
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('unit_of_measure', 16)->nullable();

            $table->unsignedBigInteger('received_by')->nullable();
            $table->time('received_at')->nullable();

            /*
             * **What condition it arrived in**, because a rejected load is a diary line that matters.
             *
             * Accepted with damage is the third value and the useful one: the load was taken because the pour was
             * booked, and the note beside it is the record that somebody said so on the day. Without the middle
             * option people mark it accepted and the fact disappears.
             */
            $table->enum('condition', ['accepted', 'accepted_with_damage', 'rejected'])->default('accepted');
            $table->string('condition_notes')->nullable();

            /*
             * **Standing on site, unconsumed** — §16.1's flag.
             *
             * Corroboration for Phase 8c's stock figure rather than a second source for it; the migration docblock
             * above sets out why the arithmetic makes stock the authority. Default false because the ordinary delivery
             * goes straight to the work face and is built in the same week.
             */
            $table->boolean('is_materials_on_site')->default(false);

            /*
             * The priced goods receipt behind this docket, where accounts have raised one. **Unconstrained**, because
             * `construction_goods_receipts` belongs to `construction_costing`.
             *
             * The null is the useful state again: a delivery site signed for that the cost ledger has never seen. Cost
             * understated, margin overstated, and nobody looking — which is why there is a query for it.
             */
            $table->unsignedBigInteger('goods_receipt_id')->nullable();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('daily_log_id');
            $table->index('goods_receipt_id');
            // The two exposure queries: what is standing on site, and what accounts have not seen.
            $table->index(['is_materials_on_site', 'goods_receipt_id']);
        });

        Schema::create('construction_daily_log_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_log_id')->constrained('construction_daily_logs')->cascadeOnDelete();

            /*
             * **Where**, from §16.5's one shared tree.
             *
             * Constrained rather than an integer, because `construction_locations` is in the spine and this module
             * requires it — §16.5 built the tree in Phase 1 precisely so that "diary photos, punch items, inspections,
             * NCRs and incidents" could all say where without five spellings of "Level 3 East".
             */
            $table->foreignId('location_id')->nullable()->constrained('construction_locations')->nullOnDelete();

            /*
             * What it is a photograph *of*.
             *
             * **`concealed_work` is the one that carries money.** Reinforcement before the pour, services before the
             * screed, a waterproofing detail before the backfill: the photograph is the only evidence the work was ever
             * there, and it is what stops an element being opened up. It is also the subject most likely to be promoted
             * to the register as as-built evidence.
             */
            $table->enum('subject', [
                'progress', 'concealed_work', 'defect', 'safety', 'damage', 'delivery', 'weather', 'as_built', 'other',
            ])->default('progress');

            $table->string('caption');
            $table->text('description')->nullable();

            $table->timestamp('taken_at')->nullable();
            $table->unsignedBigInteger('taken_by')->nullable();

            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_mime')->nullable();
            // The same sha256 the register keeps, for the same reason: the identical photograph uploaded twice from two
            // phones is the commonest cause of a gallery nobody can read.
            $table->string('file_hash', 64)->nullable();

            // What on the day this is a photograph of, where it is a photograph of something in particular. Both
            // nullable — most photos are just the day's progress.
            $table->foreignId('delivery_id')->nullable()
                ->constrained('construction_daily_log_deliveries')->nullOnDelete();
            $table->foreignId('event_id')->nullable()
                ->constrained('construction_daily_log_events')->nullOnDelete();

            /*
             * **Promoted to the ISO 19650 register**, for the handful that become as-built evidence.
             *
             * §16.1: photos live here rather than as containers because "forcing thousands of them into the register
             * creates junk containers and buries the drawings the register exists for" — and then names this action as
             * the exception. Recording which document it became keeps the two in step: the photograph stays in the
             * gallery where site can find it, and the register holds a real container with an identifier and a
             * suitability code.
             */
            $table->unsignedBigInteger('promoted_document_id')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->unsignedBigInteger('promoted_by')->nullable();

            $table->timestamps();

            $table->index('daily_log_id');
            $table->index(['subject', 'location_id']);
            $table->index('file_hash');
            $table->index('promoted_document_id');

            $table->foreign('promoted_document_id')
                ->references('id')->on('construction_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_daily_log_photos');
        Schema::dropIfExists('construction_daily_log_deliveries');
    }
};
