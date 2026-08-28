<?php

namespace App\Support;

use Closure;

/**
 * Control accounts, and the documents that are supposed to add up to them — `docs/erpnext-gap-plan.md`
 * Phase 2, item 1.
 *
 * **The gap this exists for is precise.** Ageing here iterates `Invoice` rows; the trial balance reads the
 * ledger. So a manual journal entry against Receivables — a write-off, a contra, an opening balance — moves
 * the control account and is invisible to every ageing report, and the two figures disagree with nothing to
 * say so. ERPNext does not have this problem because its journal *line* carries a party, so a write-off is
 * in Accounts Receivable automatically.
 *
 * **Rebuilding ageing from the ledger was refused, and this is what replaces it.** That would mean a party
 * on every line, which means the posting paths again, and the reward would be a report that already works.
 * The actual defect is that the two figures can disagree *silently* — so the fix is to make the
 * disagreement loud. `ConstructionCosting`'s `ReconciliationService` is the same shape, and
 * `FiscalYearClosingService`'s Opening Balance Equity blocker is the same idea with teeth.
 *
 * **A registry because the check must name no module.** `app/Health` is where facts about the installation
 * being sound live, and a check importing Invoicing would put a module's model in shared code — the thing
 * four other registries here exist to prevent. So each module declares its own control: what the ledger
 * says, what its documents say, and what to call the pair.
 */
class LedgerControls
{
    /**
     * @var array<string, array{label: string, ledger: Closure, documents: Closure, hint: string}>
     */
    private static array $controls = [];

    /**
     * @param  string  $label  how the pair is named in the alert: "Receivables"
     * @param  Closure(): float  $ledger  the control account's balance
     * @param  Closure(): float  $documents  what the documents behind it come to
     * @param  string  $hint  what somebody should look at when the two disagree
     */
    public static function register(string $label, Closure $ledger, Closure $documents, string $hint = ''): void
    {
        self::$controls[$label] = [
            'label' => $label,
            'ledger' => $ledger,
            'documents' => $documents,
            'hint' => $hint,
        ];
    }

    /**
     * Every control, compared.
     *
     * Returns one row per control with both figures and their difference, rather than a boolean: the
     * *size* of a divergence is what tells somebody whether they are looking at a rounding artefact or at
     * a write-off nobody recorded, and a check that says only "wrong" makes them go and find that out.
     *
     * @return array<int, array{label: string, ledger: float, documents: float, difference: float, hint: string}>
     */
    public static function compare(): array
    {
        $rows = [];

        foreach (self::$controls as $control) {
            $ledger = round((float) ($control['ledger'])(), 2);
            $documents = round((float) ($control['documents'])(), 2);

            $rows[] = [
                'label' => $control['label'],
                'ledger' => $ledger,
                'documents' => $documents,
                'difference' => round($ledger - $documents, 2),
                'hint' => $control['hint'],
            ];
        }

        return $rows;
    }

    /** @return array<int, string> */
    public static function labels(): array
    {
        return array_keys(self::$controls);
    }

    /** For tests, and for the same reason the other registries have one. */
    public static function flush(): void
    {
        self::$controls = [];
    }
}
