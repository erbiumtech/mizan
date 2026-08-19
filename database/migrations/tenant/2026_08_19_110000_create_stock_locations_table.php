<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where stock is — `docs/construction-management-plan.md` §6, and `docs/retail-stores-pos-plan.md` §2.1.
 *
 * **This is the cross-plan migration both plans were waiting on, and the one the construction plan calls "the
 * highest-value cross-plan note in this document".** Two plans had found the same gap — `stock_movements` has no
 * location, so `InventoryValuationService::onHand()` sums every movement for a product everywhere, and "what steel is
 * on site" is unanswerable. Each plan had its own fix, and either would have hurt the other:
 *
 *  - The retail plan proposed `stock_movements.store_id -> stores`, which would have left a building site with two bad
 *    options: a fake `stores` row carrying till settings, a sales channel and a POS registration number, or a *second*
 *    location column — at which point on-hand is a sum over two dimensions, "wrong at every location and correct in
 *    total, which is the hardest class of wrong to notice".
 *  - Construction alone would have built site stores that retail then could not use.
 *
 * **Decided 2026-08-17 and written into both plans: the location belongs to Inventory.** One table here, one column on
 * `stock_movements`, and each module points at it — `construction_jobs.stock_location_id` for a site store,
 * `stores.stock_location_id` when the retail plan builds `stores`. Both served, neither depending on the other.
 *
 * **The type enum is expanded once, for both plans, in this same migration** — which is the other half of §6's
 * coordination. Construction needs `issue` and `return`; retail needs `transfer`, `waste` and `count_adjustment`.
 * Booking a controlled site issue as an `adjustment` would make the shrinkage report meaningless, because adjustments
 * are supposed to be the *unexplained* ones.
 *
 * **`stock_location_id` is nullable, and null has one meaning: stock not tracked by location.** Not "location unknown" —
 * a null that means "we do not know which" is how a sub-ledger drifts for a year unnoticed. The alternative, non-null
 * with a default, would have to hold for every historical row in every tenant and for every future caller including
 * imports, and the migration risk of that outweighs what it buys.
 *
 * So: the backfill below gives every *existing* movement a location where any exist; `InventoryService` takes one on
 * every method and writes it; and a company that has created no locations simply has none, which is exactly the state a
 * contractor buying everything direct to site is in and the state §6 says must keep working.
 *
 * **`InvoiceService` deliberately does not set one**, and that is not an omission. An invoice has no location — the
 * retail plan's `stores.stock_location_id` is what will give a sale one, and until that exists a company selling from
 * one place has the right answer already, because `onHand()` with no location still sums everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();

            $table->string('code', 32);
            $table->string('name');

            /*
             * The kinds both plans need, in one enum.
             *
             *   warehouse — a central store
             *   shop      — a retail outlet, which `stores.stock_location_id` will point at
             *   site      — a construction site store, which `construction_jobs.stock_location_id` points at
             *   van       — stock on a vehicle, which is a real location and the commonest source of unexplained loss
             *   transit   — between two of the above, so a transfer has somewhere to be while it is in the air
             */
            $table->enum('kind', ['warehouse', 'shop', 'site', 'van', 'transit'])->default('warehouse');

            $table->text('address')->nullable();

            /*
             * The account this location's stock sits in, where a company splits its inventory account by location.
             *
             * Nullable, and null means the product's own account applies — which is what happens today and must keep
             * happening. Inventory requires `accounting`, so the table is always there.
             */
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('code');
            $table->index(['kind', 'is_active']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_location_id')->nullable()->after('product_id')
                ->constrained('stock_locations')->nullOnDelete();

            // The query both plans exist to make possible: on hand, per product, per location.
            $table->index(['product_id', 'stock_location_id']);
        });

        /*
         * **The enum, expanded once for both plans** — §6's "one coordinated change".
         *
         *   issue            — construction: material out of a site store to the work face
         *   return           — construction: unused material back into the store
         *   transfer         — retail and construction: out of one location and into another, as two movements
         *   waste            — material destroyed or spoiled, which is a known loss and not an adjustment
         *   count_adjustment — the reconciling entry after a physical count
         *
         * `adjustment` stays and keeps its meaning: the *unexplained* difference. That is the whole point of adding the
         * others — an adjustment that could mean "issued to a bricklayer" or "nobody knows" explains nothing.
         */
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', [
                'purchase', 'sale', 'adjustment',
                'issue', 'return', 'transfer', 'waste', 'count_adjustment',
            ])->change();
        });

        /*
         * **The backfill, once.**
         *
         * Only where movements already exist: a fresh tenant getting a phantom "Main store" it never asked for is a row
         * somebody has to work out the meaning of, and a company that buys everything direct to site should have no
         * locations at all. Where there is history, it has to land somewhere — an existing on-hand figure that suddenly
         * belongs to no location would make every per-location report understate while the total stayed right, which is
         * the failure §6 names.
         */
        if (DB::table('stock_movements')->exists()) {
            $locationId = DB::table('stock_locations')->insertGetId([
                'code' => 'MAIN',
                'name' => 'Main store',
                'kind' => 'warehouse',
                'notes' => 'Created by the stock-location migration to hold stock that predates locations. '
                    .'Rename it to whatever this company actually calls it.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('stock_movements')->whereNull('stock_location_id')->update([
                'stock_location_id' => $locationId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'adjustment'])->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'stock_location_id']);
            $table->dropConstrainedForeignId('stock_location_id');
        });

        Schema::dropIfExists('stock_locations');
    }
};
