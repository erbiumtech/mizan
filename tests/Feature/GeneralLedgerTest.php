<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Models\User;
use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Modules\Accounting\Services\JournalEntryService;
use Tests\AccountingTestCase;

class GeneralLedgerTest extends AccountingTestCase
{
    private JournalEntryService $entries;
    private GeneralLedgerService $ledger;
    private Account $cash;
    private Account $salaries;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entries = app(JournalEntryService::class);
        $this->ledger = app(GeneralLedgerService::class);
        $this->cash = Account::where('code', '1100')->firstOrFail();
        $this->salaries = Account::where('code', '5100')->firstOrFail();
        $this->approver = $this->makeUser('Manager', 'gl-approver@test.local');
    }

    private function postEntry(string $date, float $amount): JournalEntry
    {
        $entry = $this->entries->create(['entry_date' => $date], [
            ['account_id' => $this->salaries->id, 'debit_amount' => $amount],
            ['account_id' => $this->cash->id, 'credit_amount' => $amount],
        ]);
        $this->entries->submitForApproval($entry);
        $this->entries->approve($entry, $this->approver);

        return $this->entries->post($entry);
    }

    public function test_ledger_running_balance_is_cumulative(): void
    {
        $this->postEntry('2026-07-10', 500);
        $this->postEntry('2026-08-10', 300);

        $result = $this->ledger->accountLedger($this->salaries->fresh());

        $this->assertSame(0.0, $result['opening_balance']);
        $this->assertCount(2, $result['lines']);
        $this->assertSame(500.0, $result['lines'][0]['balance']);
        $this->assertSame(800.0, $result['lines'][1]['balance']);
        $this->assertSame(800.0, $result['closing_balance']);
    }

    public function test_ledger_date_range_computes_opening_balance(): void
    {
        $this->postEntry('2026-07-10', 500);
        $this->postEntry('2026-08-10', 300);

        $august = $this->ledger->accountLedger($this->salaries->fresh(), '2026-08-01', '2026-08-31');

        $this->assertSame(500.0, $august['opening_balance']);
        $this->assertCount(1, $august['lines']);
        $this->assertSame(800.0, $august['closing_balance']);
    }

    public function test_unposted_entries_do_not_appear_in_ledger(): void
    {
        $this->entries->create(['entry_date' => '2026-07-10'], [
            ['account_id' => $this->salaries->id, 'debit_amount' => 999],
            ['account_id' => $this->cash->id, 'credit_amount' => 999],
        ]);

        $result = $this->ledger->accountLedger($this->salaries->fresh());

        $this->assertCount(0, $result['lines']);
        $this->assertSame(0.0, $result['closing_balance']);
    }

    public function test_trial_balance_debits_equal_credits(): void
    {
        $this->postEntry('2026-07-10', 1234.56);

        $tb = $this->ledger->trialBalance();

        $this->assertTrue($tb['balanced']);
        $this->assertSame($tb['total_debits'], $tb['total_credits']);
        $this->assertSame(1234.56, $tb['total_debits']);

        $salariesRow = collect($tb['rows'])->firstWhere('code', '5100');
        $cashRow = collect($tb['rows'])->firstWhere('code', '1100');

        $this->assertSame(1234.56, $salariesRow['debit']);
        // cash is debit-normal with a negative balance -> flips to credit column
        $this->assertSame(1234.56, $cashRow['credit']);
    }
}
