<?php

namespace App\Support;

use Closure;

/**
 * Money already committed to leave or arrive, contributed by the modules that know about it.
 *
 * `docs/reports-expansion-plan.md` Phase 1.7 wants one forward-looking table: "what will hit the bank,
 * when, and whether it is already raised", from scheduled journal entries, beneficiary subscriptions and
 * recurring invoices. The first two are Accounting's; the third is Invoicing's, and the report lives in
 * Accounting.
 *
 * **So the report asks rather than imports.** `ReportPane` used to import `InvoiceService` and
 * `FbrReconciliation`, and `docs/module-packaging-plan.md` §8 spent that whole phase removing exactly this
 * edge. Reintroducing `accounting -> invoicing` for one column of one report would undo it, and
 * `ModuleBoundaryTest`'s tangled-module budget is nought. The precedent is `PaymentGenerators`: the caller
 * asks, and each module registers what it knows.
 *
 * A source is asked for a window and returns rows. Nothing registered means nothing committed, which is
 * the right answer for a company with no invoicing rather than an error — and strictly better than the
 * report knowing the names of modules the company has not bought.
 *
 * Every row is a **commitment, not a certainty**. A recurring invoice that has not been raised may be
 * cancelled; a scheduled entry may be edited. That is what makes this a report and not a forecast the
 * ledger has to honour, and it is why each row says whether it has been raised yet.
 */
class CashCommitments
{
    /**
     * @var array<string, Closure(string, string): array<int, array<string, mixed>>>
     */
    private static array $sources = [];

    /**
     * @param  string  $key  what kind of commitment this source contributes, e.g. `recurring-invoice`
     * @param  Closure(string $from, string $to): array<int, array{date: string, kind: string, description: string, amount: float, direction: string, raised: bool}>  $source
     */
    public static function register(string $key, Closure $source): void
    {
        self::$sources[$key] = $source;
    }

    /**
     * Everything committed inside a window, from every registered source, soonest first.
     *
     * Sorted here rather than by each source, because the point of the report is one timeline: three lists
     * sorted separately and concatenated is three reports on one page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function between(string $from, string $to): array
    {
        $rows = [];

        foreach (self::$sources as $source) {
            foreach ($source($from, $to) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['description']] <=> [$b['date'], $b['description']]);

        return $rows;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::$sources);
    }

    public static function flush(): void
    {
        self::$sources = [];
    }
}
