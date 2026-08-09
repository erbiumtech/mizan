<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\RegisterEntryService;
use Tests\AccountingTestCase;

/**
 * A trial balance can be perfectly in balance and still be wrong in one common
 * way: only some accounts' opening balances were entered. Every opening entry
 * credits Opening Balance Equity, so the leftover collects there instead of
 * showing up as an imbalance. The report reports it separately.
 */
class TrialBalanceOpeningEquityCheckTest extends AccountingTestCase
{
    private function report(): array
    {
        return app(FinancialReportService::class)->trialBalance();
    }

    private function openingEntry(string $accountCode, float $amount): void
    {
        app(RegisterEntryService::class)->bookRow(
            Account::where('code', $accountCode)->firstOrFail(),
            Account::where('code', Account::OPENING_BALANCE_EQUITY_CODE)->firstOrFail(),
            [
                'date' => '2026-06-30',
                'amount' => $amount,
                'direction' => 'in',
                'description' => 'Opening balance',
            ],
        );
    }

    public function test_a_book_with_no_opening_entries_reports_clear_and_unused(): void
    {
        $check = $this->report()['opening_balance_equity'];

        $this->assertSame(Account::OPENING_BALANCE_EQUITY_CODE, $check['code']);
        $this->assertSame(0.0, $check['balance']);
        $this->assertTrue($check['is_clear']);
        $this->assertFalse($check['in_use'], 'nothing has been posted to it yet');
    }

    /** One asset opened against equity leaves the equity side outstanding. */
    public function test_a_half_migrated_book_is_flagged_even_though_it_balances(): void
    {
        $this->openingEntry('1100', 250000);

        $report = $this->report();

        // The trial balance itself is fine — that is the point of this check.
        $this->assertTrue($report['balanced'], 'the entry is balanced double-entry');

        $check = $report['opening_balance_equity'];
        $this->assertFalse($check['is_clear']);
        $this->assertTrue($check['in_use']);
        $this->assertSame(250000.0, $check['balance'], 'credit-normal, so a positive leftover');
    }

    /**
     * Once the offsetting side is entered — here the owner's capital that the
     * cash actually came from — the account nets to zero and reads as clear.
     */
    public function test_it_reads_clear_once_the_opening_balances_offset(): void
    {
        $this->openingEntry('1100', 250000);

        // Debit Opening Balance Equity, credit Owner Equity: the closing move
        // that retires the opening account.
        app(RegisterEntryService::class)->bookRow(
            Account::where('code', '1100')->firstOrFail(),
            Account::where('code', Account::OPENING_BALANCE_EQUITY_CODE)->firstOrFail(),
            [
                'date' => '2026-06-30',
                'amount' => 250000,
                'direction' => 'out',
                'description' => 'Retire opening balance equity',
            ],
        );

        $check = $this->report()['opening_balance_equity'];

        $this->assertTrue($check['is_clear'], 'debits and credits now cancel');
        $this->assertTrue($check['in_use'], 'it has postings, they simply net to zero');
        $this->assertSame(0.0, $check['balance']);
    }

    public function test_the_report_page_shows_the_warning(): void
    {
        $this->openingEntry('1100', 250000);

        $html = view('reports.trial-balance', [
            'report' => $this->report(),
            'pdf' => true,
        ])->render();

        $this->assertStringContainsString('Opening Balance Equity 250,000.00', $html);
        $this->assertStringContainsString('have not been entered yet', $html);
    }

    public function test_the_report_page_shows_no_warning_when_clear(): void
    {
        $html = view('reports.trial-balance', [
            'report' => $this->report(),
            'pdf' => true,
        ])->render();

        $this->assertStringNotContainsString('have not been entered yet', $html);
    }
}
