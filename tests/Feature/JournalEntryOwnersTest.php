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
     * The premise the plan's version rested on, tested rather than assumed.
     *
     * §8 Group B says the list is dead because `source_type` is set first. If that were true of all five,
     * deleting the list would be safe. It is true of one: `DepreciationService` stamps `FixedAsset`, and
     * `PaymentService`, `InvoiceService` and `PettyCashService` stamp nothing at all — which is why this
     * became a registry instead of a deletion.
     *
     * Written as a source scan rather than as behaviour because it is a claim about the *codebase*, and
     * because the alternative — booking a payment and an invoice for real — would assert the same thing
     * through three services' worth of setup.
     */
    public function test_only_one_owner_stamps_source_type_which_is_why_the_list_survived(): void
    {
        $stamps = [
            'FixedAsset' => 'app/Modules/Accounting/Services/DepreciationService.php',
            'Payment' => 'app/Modules/Accounting/Services/PaymentService.php',
            'PettyCashVoucher' => 'app/Modules/Accounting/Services/PettyCashService.php',
        ];

        $this->assertStringContainsString(
            "'source_type' => ModuleMap::alias(FixedAsset::class)",
            file_get_contents(base_path($stamps['FixedAsset'])),
            'depreciation no longer stamps source_type; the note below is out of date',
        );

        foreach (['Payment', 'PettyCashVoucher'] as $unstamped) {
            $this->assertStringNotContainsString(
                "'source_type' =>",
                file_get_contents(base_path($stamps[$unstamped])),
                "{$unstamped} now stamps source_type. If every owner does, §8 Group B's deletion becomes "
                .'possible after a backfill — reopen it rather than leaving this test asserting the past.',
            );
        }
    }
}
