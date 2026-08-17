<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Models that own a journal entry, contributed by the module that owns them.
 *
 * A journal entry booked by a document — an invoice, a stock movement, a payment — is the accounting
 * half of that document, and editing it from the register would desynchronise the two silently. The
 * register therefore asks "does anything own this entry" before allowing a change, and it used to answer
 * by naming five model classes in an array. Two of them were in other modules, which made
 * `accounting -> invoicing` and `accounting -> inventory` edges out of a *guard*.
 *
 * So the direction is reversed, as it was for the Projects tab in phase 7: Accounting asks the registry,
 * and each module registers what it owns from its own service provider. A module that is not installed
 * registers nothing and owns nothing, which is exactly right — there are no invoices to desynchronise.
 *
 * **Why not the plan's version.** `docs/module-packaging-plan.md` §8 Group B calls this array "a legacy
 * fallback that is already dead code", to be deleted after backfilling `journal_entries.source_type`.
 * That premise does not hold: of the five owners, only `FixedAsset` stamps `source_type` today
 * (`DepreciationService`), and `Payment`, `Invoice` and `PettyCashVoucher` stamp nothing at all — the
 * array is the *only* thing protecting their entries, and `RegisterEntryEditTest` asserts exactly that
 * for two of them. Deleting it would have made those entries editable from the register, which is a
 * data-integrity regression wearing the clothes of a lint fix. Registering the same lookup removes the
 * same two edges and changes no behaviour.
 *
 * @see \App\Modules\Accounting\Services\RegisterEntryService::immutableReason()
 */
class JournalEntryOwners
{
    /**
     * Label => model class, in the order they are checked.
     *
     * A label rather than a class basename because it is read by a person in a sentence: "This entry
     * belongs to a petty cash voucher (#12)."
     *
     * @var array<string, class-string<Model>>
     */
    private static array $owners = [];

    /**
     * @param  string  $label  how the owner is named in the message shown to the user
     * @param  class-string<Model>  $model  a model with a `journal_entry_id` column
     */
    public static function register(string $label, string $model): void
    {
        self::$owners[$label] = $model;
    }

    /** @return array<string, class-string<Model>> */
    public static function all(): array
    {
        return self::$owners;
    }

    /**
     * The owner of this entry, if anything owns it.
     *
     * @return array{label: string, key: int|string}|null
     */
    public static function ownerOf(int|string $journalEntryId): ?array
    {
        foreach (self::$owners as $label => $model) {
            $owner = $model::query()->where('journal_entry_id', $journalEntryId)->first();

            if ($owner !== null) {
                return ['label' => $label, 'key' => $owner->getKey()];
            }
        }

        return null;
    }

    /** For tests that need to assert the register's behaviour without contributions. */
    public static function flush(): void
    {
        self::$owners = [];
    }
}
