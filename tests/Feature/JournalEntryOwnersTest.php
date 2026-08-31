<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\PettyCashVoucher;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\JournalEntryOwners;
use Tests\TestCase;

/**
 * Every document that owns a journal entry says so, and the register can find out without naming it.
 *
 * The register refuses to edit an entry that belongs to a document, because the entry is that document's
 * accounting half and the two would desynchronise silently. It used to decide that from a list of five
 * model classes — two of them in modules Accounting does not require, which turned a *guard* into an
 * `accounting -> invoicing` and an `accounting -> inventory` edge.
 *
 * The list is now a registry each module writes to. That is a strictly more fragile arrangement in one
 * specific way: a registration that is deleted, or a provider that stops booting, takes the protection
 * with it and nothing else complains — the register would simply start allowing the edit. This file is
 * the thing that complains.
 *
 * `docs/module-packaging-plan.md` §8 Group B proposed deleting the list outright as "already dead code",
 * on the grounds that `journal_entries.source_type` short-circuits ahead of it. That is true of
 * `FixedAsset` and of nothing else — see the test at the bottom, which is the evidence.
 */
class JournalEntryOwnersTest extends TestCase
{
    /**
     * The five owners, and the words the register uses for them.
     *
     * The labels are asserted because they are read by a person in a sentence — "This entry belongs to a
     * petty cash voucher (#12)" — and `RegisterEntryEditTest` matches on them.
     */
    private const EXPECTED = [
        'a payment' => Payment::class,
        'a petty cash voucher' => PettyCashVoucher::class,
        'a fixed asset' => FixedAsset::class,
        'an invoice' => Invoice::class,
        'a stock movement' => StockMovement::class,
    ];

    public function test_every_owner_is_registered_by_its_own_module(): void
    {
        $registered = JournalEntryOwners::all();

        foreach (self::EXPECTED as $label => $model) {
            $this->assertArrayHasKey(
                $label,
                $registered,
                "nothing registers '{$label}' — the register will now allow editing an entry owned by ".class_basename($model),
            );

            $this->assertSame($model, $registered[$label]);
        }
    }

    /**
     * And nothing else, so a stray registration cannot make an unrelated model immutable.
     *
     * Asserted as a count rather than a diff so that adding an owner fails here first — a new document
     * that books entries needs a deliberate decision about whether the register may edit them.
     */
    public function test_the_registry_holds_exactly_the_known_owners(): void
    {
        $this->assertCount(count(self::EXPECTED), JournalEntryOwners::all());
    }

    /**
     * Each owner names a model that actually has the column the lookup queries.
     *
     * A registration pointing at a model without `journal_entry_id` would throw on the first register
     * row rendered, which is a screen nobody can open rather than a test anybody sees.
     */
    public function test_every_registered_owner_has_the_column_the_lookup_uses(): void
    {
        foreach (JournalEntryOwners::all() as $label => $model) {
            $this->assertContains(
                'journal_entry_id',
                (new $model)->getFillable(),
                "[{$label}] {$model} does not carry journal_entry_id, so the lookup cannot find it",
            );
        }
    }

    /**
     * The premise this registry rested on has changed, and the registry survives the change.
     *
     * This test used to assert the opposite: that of the five owners only `DepreciationService` stamped
     * `source_type`, which is why `docs/module-packaging-plan.md` §8 Group B's "delete the list" was
     * refused. It ended with an instruction — *"If every owner does, that deletion becomes possible after a
     * backfill — reopen it rather than leaving this test asserting the past."*
     *
     * `docs/erpnext-gap-plan.md` Phase 1 is that event: every posting path now records what produced it.
     * So the question is reopened here, and the answer is still **no**, for a reason the original could not
     * have known:
     *
     *  - **Stamping is forward-looking.** Every entry posted from Phase 1 onward carries its source; every
     *    entry posted before it does not, and `accounting:backfill-entry-sources` is opt-in — it is a
     *    command somebody runs, not a migration. A company that upgrades and does not run it has years of
     *    invoice postings with a null source.
     *  - **The register's guard must hold for those.** `RegisterEntryService::immutableReason()` is what
     *    stops somebody editing the accounting half of an invoice from the register. Deleting the list
     *    would make exactly the *historical* entries editable — the ones with the most to lose — and
     *    nothing would report it.
     *
     * So the registry keeps both jobs: it guards entries whose source is null, and it is the list the
     * backfill walks to fill those in. The two are the same fact from opposite ends.
     *
     * Still a source scan, because it is still a claim about the codebase — that every posting path
     * attributes — rather than about behaviour. The behavioural half, that an entry with no source is
     * still protected and that the backfill can fill it in from these same owners, is
     * `LedgerDimensionsTest`, where a database already exists.
     */
    public function test_every_owner_now_stamps_its_source_and_the_registry_still_guards_history(): void
    {
        $attributes = [
            'FixedAsset' => 'app/Modules/Accounting/Services/DepreciationService.php',
            'Payment' => 'app/Modules/Accounting/Services/PaymentService.php',
            'PettyCashVoucher' => 'app/Modules/Accounting/Services/PettyCashService.php',
            'Invoice' => 'app/Modules/Invoicing/Services/InvoiceService.php',
            'StockMovement' => 'app/Modules/Inventory/Services/InventoryService.php',
        ];

        foreach ($attributes as $owner => $path) {
            $source = file_get_contents(base_path($path));

            $this->assertTrue(
                str_contains($source, "'source_type' =>") || str_contains($source, 'attributeTo('),
                "[{$owner}] no longer attributes its postings. Phase 1 of docs/erpnext-gap-plan.md put a "
                .'source on every posting path; a path that stops doing it reports as Unassigned in every '
                .'dimension report, silently.',
            );
        }

    }
}
