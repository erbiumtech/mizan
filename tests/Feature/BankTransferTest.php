<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\BankTransferService;
use App\Modules\Accounting\Services\FinancialReportService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Moving money between the company's own accounts.
 *
 * It was already possible, and that was the problem: the register's Add Transaction
 * books any two-line entry, so the same 50,000 moved from the bank to petty cash
 * could be entered as a credit on one or a debit on the other, by two people, with
 * two descriptions. The money came out right and the books read as two unrelated
 * events.
 */
class BankTransferTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Account $bank;

    private Account $pettyCash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'transfer@test.local'));
        $this->setCurrentTenant();

        $this->bank = Account::where('code', '1100')->firstOrFail();
        $this->pettyCash = Account::where('code', '1150')->firstOrFail();
    }

    private function transfers(): BankTransferService
    {
        return app(BankTransferService::class);
    }

    private function net(Account $account): float
    {
        $query = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        return round((float) $query->sum('debit_amount') - (float) (clone $query)->sum('credit_amount'), 2);
    }

    public function test_it_takes_from_one_and_gives_to_the_other(): void
    {
        $entry = $this->transfers()->transfer($this->bank, $this->pettyCash, 50000, '2026-08-04');

        $this->assertTrue((bool) $entry->is_posted);
        $this->assertSame(-50000.0, $this->net($this->bank), 'out of the bank');
        $this->assertSame(50000.0, $this->net($this->pettyCash), 'into petty cash');
    }

    /**
     * One direction, whichever way round the accounts are given. This is the whole
     * point: the generic entry let the typist choose, and two people chose
     * differently.
     */
    public function test_the_direction_is_fixed_by_which_account_is_which(): void
    {
        $this->transfers()->transfer($this->pettyCash, $this->bank, 20000, '2026-08-04');

        $this->assertSame(20000.0, $this->net($this->bank));
        $this->assertSame(-20000.0, $this->net($this->pettyCash));
    }

    public function test_it_is_marked_as_a_transfer(): void
    {
        // So a year later it can be told apart from a payment.
        $entry = $this->transfers()->transfer($this->bank, $this->pettyCash, 50000, '2026-08-04');

        $this->assertSame(BankTransferService::ENTRY_TYPE, $entry->entry_type);
    }

    public function test_it_describes_itself_the_same_way_every_time(): void
    {
        $entry = $this->transfers()->transfer($this->bank, $this->pettyCash, 50000, '2026-08-04');

        $this->assertStringContainsString('Transfer 1100 → 1150', $entry->memo);
        $this->assertStringContainsString('Transfer 1100 → 1150', $entry->lines()->first()->description);
    }

    public function test_a_description_can_be_given_instead(): void
    {
        $entry = $this->transfers()->transfer(
            $this->bank, $this->pettyCash, 50000, '2026-08-04',
            reference: 'CHQ-9001',
            note: 'Float for the office',
        );

        $this->assertStringContainsString('Float for the office', $entry->memo);
        $this->assertSame('CHQ-9001', $entry->reference);
    }

    public function test_one_account_cannot_transfer_to_itself(): void
    {
        $this->expectExceptionMessage('needs two different accounts');

        $this->transfers()->transfer($this->bank, $this->bank, 50000, '2026-08-04');
    }

    public function test_a_transfer_of_nothing_is_refused(): void
    {
        $this->expectExceptionMessage('must be a positive amount');

        $this->transfers()->transfer($this->bank, $this->pettyCash, 0, '2026-08-04');
    }

    /**
     * Money leaving the company is a payment. Calling it a transfer would put an
     * expense in the cash section of the cash flow and hide it from the P&L.
     */
    public function test_it_refuses_an_account_that_is_not_cash(): void
    {
        $rent = Account::where('code', '5700')->firstOrFail();

        $this->expectExceptionMessage('not one of the company');

        $this->transfers()->transfer($this->bank, $rent, 50000, '2026-08-04');
    }

    public function test_the_offered_accounts_are_the_register_accounts(): void
    {
        $codes = $this->transfers()->accounts()->pluck('code')->all();

        $this->assertContains('1100', $codes);
        $this->assertContains('1150', $codes);
        $this->assertNotContains('5700', $codes, 'an expense account is not somewhere money sits');
        $this->assertNotContains('1250', $codes, 'nor is a receivable');
    }

    /** It moves cash about; it does not change how much there is. */
    public function test_it_does_not_change_the_cash_position(): void
    {
        $entry = JournalEntry::query()->first(); // none yet
        $this->assertNull($entry);

        $this->transfers()->transfer($this->bank, $this->pettyCash, 50000, '2026-08-04');

        $reports = app(FinancialReportService::class);
        $flow = $reports->cashFlow('2026-08-01', '2026-08-31');

        $this->assertSame(0.0, $flow['net_change']);
        $this->assertSame(0.0, $reports->cashAt('2026-08-31'));
        $this->assertTrue($flow['reconciles']);
        $this->assertTrue($reports->balanceSheet('2026-08-31')['balanced']);
    }

    public function test_it_shows_in_both_registers(): void
    {
        $this->transfers()->transfer($this->bank, $this->pettyCash, 50000, '2026-08-04');

        $register = app(\App\Modules\Accounting\Services\RegisterEntryService::class);

        $bankRows = $register->registerRows($this->bank, null, '2026-08-31')['rows'];
        $pettyRows = $register->registerRows($this->pettyCash, null, '2026-08-31')['rows'];

        $this->assertSame(50000.0, (float) $bankRows[0]['credit'], 'out, on the bank');
        $this->assertSame(50000.0, (float) $pettyRows[0]['debit'], 'in, on petty cash');
    }

    public function test_the_action_is_offered_on_the_register(): void
    {
        Livewire::test(AccountRegister::class)
            ->assertActionVisible(TestAction::make('transfer'));
    }
}
