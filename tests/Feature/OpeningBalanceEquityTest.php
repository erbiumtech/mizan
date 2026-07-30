<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\RegisterEntryService;
use Tests\AccountingTestCase;

/**
 * `3300 Opening Balance Equity` is the counter-account used when a book is
 * brought onto the system: debit the cash/bank account, credit this. Keeping it
 * separate from Owner Equity means an opening balance is never mistaken for a
 * genuine capital contribution.
 *
 * The register derives its opening balance from posted entries dated before the
 * From date (RegisterEntryService::balanceBefore), so this account has to be
 * postable and offered in the Transfer dropdown for that flow to work.
 */
class OpeningBalanceEquityTest extends AccountingTestCase
{
    private function account(string $code): ?Account
    {
        return Account::where('code', $code)->first();
    }

    public function test_the_account_exists_under_equity(): void
    {
        $account = $this->account('3300');

        $this->assertNotNull($account, '3300 Opening Balance Equity should be seeded');
        $this->assertSame('Opening Balance Equity', $account->name);
        $this->assertSame('equity', $account->type);
        $this->assertSame($this->account('3000')?->id, $account->parent_id, 'it should sit under 3000 Equity');
    }

    /** Equity is credit-normal; the model derives this from the type. */
    public function test_it_is_credit_normal(): void
    {
        $this->assertSame('credit', $this->account('3300')->normal_balance);
    }

    public function test_it_is_postable_and_active(): void
    {
        $account = $this->account('3300');

        $this->assertTrue($account->allow_manual_entry, 'opening entries post directly to it');
        $this->assertTrue($account->is_active);
    }

    /** It must be selectable as the Transfer account in the register. */
    public function test_it_appears_in_the_register_transfer_options(): void
    {
        $bank = Account::where('code', '1100')->firstOrFail();

        $labels = app(RegisterEntryService::class)
            ->transferOptions($bank)
            ->pluck('label')
            ->all();

        $this->assertContains('Equity:3300 Opening Balance Equity', $labels);
    }

    /** Booking an opening balance through the register behaves as expected. */
    public function test_an_opening_balance_posts_and_shows_as_the_register_opening(): void
    {
        $service = app(RegisterEntryService::class);

        $bank = Account::where('code', '1100')->firstOrFail();
        $equity = Account::where('code', '3300')->firstOrFail();

        $service->bookRow($bank, $equity, [
            'date' => '2026-06-30',
            'amount' => 250000,
            'direction' => 'in',
            'description' => 'Opening balance',
            'num' => 'OB-1',
        ]);

        // Dated before the From date, so it lands in the opening figure rather
        // than as a row in the period.
        $ledger = $service->registerRows($bank, '2026-07-01', '2027-06-30');

        $this->assertSame(250000.0, $ledger['opening_balance']);
        $this->assertSame([], $ledger['rows']);
        $this->assertSame(250000.0, $ledger['closing_balance']);
    }

    /** Without a From date there is no "before", so nothing opens. */
    public function test_with_no_from_date_the_opening_balance_is_zero(): void
    {
        $service = app(RegisterEntryService::class);

        $bank = Account::where('code', '1100')->firstOrFail();
        $equity = Account::where('code', '3300')->firstOrFail();

        $service->bookRow($bank, $equity, [
            'date' => '2026-06-30',
            'amount' => 100000,
            'direction' => 'in',
            'description' => 'Opening balance',
        ]);

        $ledger = $service->registerRows($bank);

        $this->assertSame(0.0, $ledger['opening_balance']);
        $this->assertCount(1, $ledger['rows'], 'the entry shows as a row instead');
    }
}
