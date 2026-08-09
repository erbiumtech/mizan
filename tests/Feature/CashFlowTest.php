<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\CashFlow;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\JournalEntryService;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The cash flow statement.
 *
 * A CashFlowChart widget shipped and no statement did, so the shape of a month was
 * visible and its explanation was not.
 *
 * Built so it cannot fail to tie: debits less credits across every account is
 * zero, so the cash effect of a period is exactly the negative of every non-cash
 * account's movement. Classify each non-cash account into operating, investing or
 * financing exactly once and the three subtotals must sum to the change in cash.
 * These tests check that the classification is right, not only that it adds up.
 */
class CashFlowTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'cash@test.local'));
        $this->setCurrentTenant();
    }

    private function postEntry(string $date, array $lines, string $memo = 'Test'): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => $memo],
            collect($lines)->map(fn (array $line): array => [
                'account_id' => Account::where('code', $line[0])->firstOrFail()->id,
                $line[1] => $line[2],
            ])->all(),
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function flow(string $from = '2026-07-01', string $to = '2026-08-31'): array
    {
        return app(FinancialReportService::class)->cashFlow($from, $to);
    }

    /** The test the plan named: closing cash must be the cash on the balance sheet. */
    public function test_closing_cash_equals_the_cash_on_the_balance_sheet(): void
    {
        $this->postEntry('2026-07-02', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]], 'Capital');
        $this->postEntry('2026-07-10', [['5700', 'debit_amount', 92000], ['1100', 'credit_amount', 92000]], 'Rent');
        $this->postEntry('2026-07-20', [['1150', 'debit_amount', 50000], ['1100', 'credit_amount', 50000]], 'Petty cash float');

        $reports = app(FinancialReportService::class);
        $flow = $this->flow();

        $this->assertTrue($flow['reconciles']);
        $this->assertSame(408000.0, $flow['closing_cash'], '500,000 in, 92,000 out');
        $this->assertSame(
            $flow['closing_cash'],
            $reports->cashAt('2026-08-31'),
            'the statement and the ledger must agree on how much cash there is',
        );

        // And cash on the balance sheet is those same accounts.
        $sheetCash = collect($reports->balanceSheet('2026-08-31')['assets']['rows'])
            ->filter(fn (array $row): bool => str_starts_with($row['code'], '11'))
            ->sum('amount');

        $this->assertSame($flow['closing_cash'], round($sheetCash, 2));
    }

    /** Moving cash between two cash accounts is not a cash flow at all. */
    public function test_a_transfer_between_cash_accounts_nets_to_nothing(): void
    {
        $this->postEntry('2026-07-02', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);

        $before = $this->flow()['net_change'];

        $this->postEntry('2026-07-20', [['1150', 'debit_amount', 50000], ['1100', 'credit_amount', 50000]], 'Float');

        $this->assertSame($before, $this->flow()['net_change']);
    }

    public function test_buying_a_fixed_asset_is_investing_not_operating(): void
    {
        $this->postEntry('2026-07-02', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);
        $this->postEntry('2026-07-15', [['1400', 'debit_amount', 200000], ['1100', 'credit_amount', 200000]], 'Laptops');

        $flow = $this->flow();

        $this->assertSame(-200000.0, $flow['investing']['total'], 'cash out');
        $this->assertSame('1400', $flow['investing']['rows'][0]['code']);
        $this->assertTrue($flow['reconciles']);
    }

    public function test_capital_introduced_is_financing(): void
    {
        $this->postEntry('2026-07-02', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]], 'Capital');

        $flow = $this->flow();

        $this->assertSame(500000.0, $flow['financing']['total']);
        $this->assertSame(0.0, $flow['investing']['total']);
        $this->assertTrue($flow['reconciles']);
    }

    /**
     * Depreciation reduces profit and moves no cash, so it is added back. If
     * accumulated depreciation were classified with the other 15xx assets it would
     * appear as an investing outflow, which would say the company spent cash it did
     * not spend.
     */
    public function test_depreciation_is_added_back_in_operating(): void
    {
        $this->postEntry('2026-07-02', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);
        $this->postEntry('2026-07-15', [['1400', 'debit_amount', 200000], ['1100', 'credit_amount', 200000]]);
        $this->postEntry('2026-07-31', [['5990', 'debit_amount', 20000], ['1500', 'credit_amount', 20000]], 'Depreciation');

        $flow = $this->flow();

        $this->assertSame(-20000.0, $flow['operating']['net_income'], 'the charge reduced profit');
        $this->assertSame(20000.0, $flow['operating']['depreciation'], 'and is added straight back');
        $this->assertSame(0.0, $flow['operating']['total'], 'so operating cash is unaffected');
        $this->assertSame(-200000.0, $flow['investing']['total'], 'only the purchase is investing');
        $this->assertTrue($flow['reconciles']);
    }

    /**
     * An expense incurred and not yet paid costs no cash this period. Profit falls;
     * the payable rises by the same amount and cancels it.
     */
    public function test_an_unpaid_expense_does_not_reduce_cash(): void
    {
        $this->postEntry('2026-07-02', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);
        $this->postEntry('2026-07-15', [['5700', 'debit_amount', 92000], ['2400', 'credit_amount', 92000]], 'Rent owed');

        $flow = $this->flow();

        $this->assertSame(-92000.0, $flow['operating']['net_income']);
        $this->assertSame(
            92000.0,
            collect($flow['operating']['working_capital'])->firstWhere('code', '2400')['amount'],
        );
        $this->assertSame(0.0, $flow['operating']['total']);
        $this->assertSame(500000.0, $flow['net_change'], 'only the capital moved cash');
    }

    public function test_the_opening_balance_is_what_came_before_the_period(): void
    {
        $this->postEntry('2026-06-15', [['1100', 'debit_amount', 300000], ['3100', 'credit_amount', 300000]], 'Before');
        $this->postEntry('2026-07-15', [['1100', 'debit_amount', 100000], ['3100', 'credit_amount', 100000]], 'During');

        $flow = $this->flow();

        $this->assertSame(300000.0, $flow['opening_cash']);
        $this->assertSame(100000.0, $flow['net_change']);
        $this->assertSame(400000.0, $flow['closing_cash']);
    }

    public function test_an_empty_period_reconciles_at_nothing(): void
    {
        $flow = $this->flow();

        $this->assertTrue($flow['reconciles']);
        $this->assertSame(0.0, $flow['net_change']);
        $this->assertSame(0.0, $flow['closing_cash']);
    }

    public function test_the_page_and_the_printable_version_render(): void
    {
        $this->postEntry('2026-07-02', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);

        Livewire::test(CashFlow::class)
            ->set('data.from', '2026-07-01')
            ->set('data.to', '2026-08-31')
            ->assertSee('500,000.00')
            ->assertSee('Reconciles');

        $url = route('reports.cash-flow', [
            'company' => $this->tenant->slug,
            'from' => '2026-07-01',
            'to' => '2026-08-31',
        ]);

        $this->get($url)->assertOk()->assertSee('Cash Flow')->assertSee('500,000.00');
        $this->get($url.'&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
