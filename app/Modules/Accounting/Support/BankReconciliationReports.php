<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Support\Reporting\ReportShapes;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The bank reconciliation statement — `docs/reports-expansion-plan.md` Phase 2.6.
 *
 * The four lines every year-end file wants, per bank account: what the bank says, less the cheques it has not
 * seen yet, plus the deposits it has not credited yet, against what the ledger says. `reconciled_at` is what
 * makes it possible — a ledger line matched to a statement line carries it, so a posted line *without* it is
 * by definition something the bank has not seen.
 *
 * **The plan expected this to be a report about completed statements, and it cannot be.** `complete()` refuses
 * a statement whose closing balance does not equal the ledger balance exactly. An unpresented cheque makes
 * those two differ by definition — that is what unpresented means — so a statement carrying one can never be
 * completed, and every *completed* statement in this application has, necessarily, nothing to reconcile. The
 * interesting rows are therefore the statements still open, and this report is where the figure `complete()`
 * rejects is finally named and added up.
 *
 * **One statement per account: the latest at or before the date.** A reconciliation is a position at a moment,
 * and the moment is a statement date rather than the report date — asking for it "as at" September when the
 * last statement is 31 August means the August reconciliation, not an empty page. Accounts with no statement
 * at all are absent rather than listed as nought: nothing has ever been reconciled there, which is a
 * different fact from reconciling to nil.
 */
class BankReconciliationReports
{
    use ReportShapes;

    public function statement(string $asOf): array
    {
        /*
         * The latest statement at or before the date, per account, in one query.
         *
         * Ordered newest first and de-duplicated in PHP by account, because "the latest per group" in SQL is
         * either a correlated subquery or a window function and this table has one row per account per month.
         */
        $statements = BankStatement::query()
            ->whereDate('statement_date', '<=', $asOf)
            ->with('account')
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->get()
            ->unique('account_id')
            ->values();

        if ($statements->isEmpty()) {
            return $this->emptyReport($asOf);
        }

        $ledger = $this->ledgerBalances($statements);
        $unpresented = $this->unpresentedByAccount($statements);
        $unbooked = $this->unbookedStatementLines($statements);

        $rows = [];
        $perBank = 0.0;
        $perLedger = 0.0;
        $chequeTotal = 0.0;
        $transitTotal = 0.0;
        $outOfBalance = [];
        $unbookedLines = 0;

        foreach ($statements as $statement) {
            $accountId = (int) $statement->account_id;
            $bank = round((float) $statement->closing_balance, 2);
            $books = round((float) ($ledger[$accountId] ?? 0.0), 2);
            $cheques = round((float) ($unpresented[$accountId]['cheques'] ?? 0.0), 2);
            $transit = round((float) ($unpresented[$accountId]['transit'] ?? 0.0), 2);

            /*
             * The identity this report exists to state.
             *
             * A bank account is debit-normal. A cheque we have written and the bank has not paid is a credit
             * in the ledger the bank has not made, so the bank reads *higher* than the books by that amount;
             * a deposit we have banked and the bank has not credited is the mirror. So the bank balance
             * adjusted for both is what the ledger should say, and anything left over is real: something on
             * the statement that was never booked, most often a charge or interest.
             */
            $adjusted = round($bank - $cheques + $transit, 2);
            $difference = round($books - $adjusted, 2);

            $rows[] = [
                ($statement->account?->code ? $statement->account->code.' · ' : '')
                    .($statement->account?->name ?? 'Account '.$accountId),
                $statement->statement_date->toDateString().($statement->isCompleted() ? '' : ' (open)'),
                number_format($bank, 0),
                $cheques > 0 ? '('.number_format($cheques, 0).')' : '—',
                $transit > 0 ? number_format($transit, 0) : '—',
                number_format($books, 0),
                abs($difference) < 0.01 ? '—' : number_format($difference, 0),
            ];

            $perBank += $adjusted;
            $perLedger += $books;
            $chequeTotal += $cheques;
            $transitTotal += $transit;
            $unbookedLines += (int) ($unbooked[$statement->getKey()] ?? 0);

            if (abs($difference) >= 0.01) {
                $outOfBalance[] = $statement->account?->code ?? (string) $accountId;
            }
        }

        return $this->table(
            'BankReconciliationStatement',
            'Bank Reconciliation Statement',
            $this->subtitle('as at '.$asOf),
            ['Account', 'Statement', 'Per bank', 'Unpresented', 'In transit', 'Per books', 'Difference'],
            'minmax(0, 1fr) 11rem 9rem 10rem 9rem 9rem 9rem',
            [2, 3, 4, 5, 6],
            $rows,
            [
                ['label' => 'PER BOOKS', 'value' => round($perLedger, 2), 'accent' => true],
                ['label' => 'PER BANK, ADJUSTED', 'value' => round($perBank, 2), 'accent' => false],
            ],
            $this->note($statements, $outOfBalance, round($chequeTotal, 2), round($transitTotal, 2), $unbookedLines),
            [
                'Total — '.$statements->count().' accounts',
                '',
                '',
                $chequeTotal > 0 ? '('.number_format($chequeTotal, 0).')' : '—',
                $transitTotal > 0 ? number_format($transitTotal, 0) : '—',
                number_format($perLedger, 0),
                '',
            ],
            'No bank statement at or before this date.',
        );
    }

    /**
     * Each account's ledger balance at *its own* statement date.
     *
     * One query per distinct statement date rather than per account, which is normally one: statements are cut
     * at month ends and every account's is the same day.
     *
     * `BankReconciliationService::ledgerBalance()` answers this for a single statement and is what
     * `complete()` checks against — but it goes through `accountLedger()`, which builds every row of the
     * account's ledger to return one closing figure. That is the right shape for one statement on screen and
     * the wrong one for a report over every bank account. `BankReconciliationStatementReportTest` asserts the
     * two agree, statement by statement, which is the same protection the stocktake's batched valuation
     * carries for the same reason.
     *
     * @param  Collection<int, BankStatement>  $statements
     * @return array<int, float>
     */
    private function ledgerBalances(Collection $statements): array
    {
        $ledger = app(GeneralLedgerService::class);
        $balances = [];

        foreach ($statements->groupBy(fn (BankStatement $s): string => $s->statement_date->toDateString()) as $date => $group) {
            $balances += $ledger->balancesFor($group->pluck('account_id')->unique()->values()->all(), $date);
        }

        return $balances;
    }

    /**
     * What the bank has not seen, per account, split by direction.
     *
     * A posted line against the bank account with no `reconciled_at` and dated on or before the statement
     * date: the match is what sets that column, so its absence *is* the definition of unpresented. Excluded
     * statement lines clear it back to null deliberately, which is correct — an excluded line is one nobody
     * claims ties to the ledger.
     *
     * One query for every account, bucketed in PHP, because each account's cut-off is its own statement date.
     * Fetching to the latest of them and filtering per account is one pass over a small set; a query per
     * account is the per-row shape the plan's risk list warns about.
     *
     * @param  Collection<int, BankStatement>  $statements
     * @return array<int, array{cheques: float, transit: float, count: int}>
     */
    private function unpresentedByAccount(Collection $statements): array
    {
        $cutoff = $statements->map(fn (BankStatement $s): string => $s->statement_date->toDateString())->max();
        $accountIds = $statements->pluck('account_id')->unique()->values()->all();

        $lines = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereNull('journal_entry_lines.reconciled_at')
            ->where('journal_entries.is_posted', true)
            ->whereDate('journal_entries.entry_date', '<=', $cutoff)
            ->select([
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit_amount',
                'journal_entry_lines.credit_amount',
                'journal_entries.entry_date',
            ])
            ->get();

        $cutoffs = $statements
            ->mapWithKeys(fn (BankStatement $s): array => [
                (int) $s->account_id => $s->statement_date->toDateString(),
            ])
            ->all();

        $totals = [];

        foreach ($lines as $line) {
            $accountId = (int) $line->account_id;
            $date = $line->entry_date instanceof DateTimeInterface
                ? $line->entry_date->format('Y-m-d')
                : substr((string) $line->entry_date, 0, 10);

            if ($date > ($cutoffs[$accountId] ?? '')) {
                continue; // after this account's own statement date
            }

            $totals[$accountId] ??= ['cheques' => 0.0, 'transit' => 0.0, 'count' => 0];

            // A credit to a debit-normal bank account is money leaving: a cheque or payment the bank has not
            // taken yet. A debit is money arriving that the bank has not credited yet.
            $totals[$accountId]['cheques'] += (float) $line->credit_amount;
            $totals[$accountId]['transit'] += (float) $line->debit_amount;
            $totals[$accountId]['count']++;
        }

        return $totals;
    }

    /**
     * Statement lines still tied to nothing in the ledger, per statement.
     *
     * The usual explanation for a difference that survives both adjustments: a charge or interest the bank
     * applied and nobody booked. Counted rather than valued, because an unmatched line's amount is the bank's
     * figure and adding it to the reconciliation would be asserting the entry it should have produced.
     *
     * @param  Collection<int, BankStatement>  $statements
     * @return array<int, int>
     */
    private function unbookedStatementLines(Collection $statements): array
    {
        return BankStatementLine::query()
            ->whereIn('bank_statement_id', $statements->modelKeys())
            ->where('match_status', BankStatementLine::STATUS_UNMATCHED)
            ->groupBy('bank_statement_id')
            ->selectRaw('bank_statement_id, COUNT(*) as lines')
            ->pluck('lines', 'bank_statement_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Whether every account reconciles, and what to look at where one does not.
     *
     * The unpresented totals are stated even when everything balances, because they are the report: a nil
     * difference with 400,000 of unpresented cheques behind it is a very different position from a nil
     * difference with none, and only one of them means the bank balance is the money available.
     *
     * @param  Collection<int, BankStatement>  $statements
     * @param  array<int, string>  $outOfBalance
     */
    private function note(
        Collection $statements,
        array $outOfBalance,
        float $cheques,
        float $transit,
        int $unbookedLines,
    ): string {
        $open = $statements->reject(fn (BankStatement $s): bool => $s->isCompleted())->count();

        $clauses = [$statements->count().' accounts'];

        $adjustments = array_filter([
            $cheques > 0 ? number_format($cheques, 0).' unpresented' : null,
            $transit > 0 ? number_format($transit, 0).' in transit' : null,
        ]);

        $clauses = [...$clauses, ...$adjustments];

        $clauses[] = $outOfBalance === []
            ? 'every account reconciles to the books'
            : count($outOfBalance).' out of balance ('.implode(', ', $outOfBalance).')';

        if ($unbookedLines > 0) {
            $clauses[] = $unbookedLines.' statement lines are matched to nothing — a charge or interest '
                .'nobody booked is the usual reason, and the usual difference';
        }

        if ($open > 0 && $cheques > 0) {
            // The finding behind this report. `complete()` compares the statement balance against the ledger
            // and refuses anything else, so the very statements that need reconciling are the ones it will
            // not close.
            $clauses[] = $open.' statements are still open, and a statement carrying unpresented items '
                .'cannot be completed at all — the closing balance is checked against the ledger exactly';
        }

        return mb_strtoupper(implode(' · ', $clauses));
    }

    /** No statement anywhere at or before the date, said plainly. */
    private function emptyReport(string $asOf): array
    {
        return $this->table(
            'BankReconciliationStatement',
            'Bank Reconciliation Statement',
            $this->subtitle('as at '.$asOf),
            ['Account', 'Statement', 'Per bank', 'Unpresented', 'In transit', 'Per books', 'Difference'],
            'minmax(0, 1fr) 11rem 9rem 10rem 9rem 9rem 9rem',
            [2, 3, 4, 5, 6],
            [],
            [
                ['label' => 'PER BOOKS', 'value' => 0.0, 'accent' => true],
                ['label' => 'PER BANK, ADJUSTED', 'value' => 0.0, 'accent' => false],
            ],
            'NO BANK STATEMENT AT OR BEFORE THIS DATE',
            null,
            'No bank statement at or before this date. A reconciliation is a position at a statement date, '
                .'so an account that has never had one imported has nothing to show here.',
        );
    }
}
