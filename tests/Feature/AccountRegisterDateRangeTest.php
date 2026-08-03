<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\RegisterEntryService;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The Account Register agreeing with the Profit & Loss and the Trial Balance.
 *
 * It did not, and the ledger was fine — the three pages simply stopped at
 * different dates. P&L and Trial Balance both default to today; the register had
 * no end date at all. A payment's entry is dated at its value date, so payments
 * scheduled ahead appeared in the register and in neither of the other two, and
 * the totals disagreed with nothing on screen to say why.
 */
class AccountRegisterDateRangeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'register@test.local'));
        $this->setCurrentTenant();
    }

    private function postEntry(string $date, float $amount, string $memo, string $expenseCode = '5700'): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => $memo],
            [
                ['account_id' => Account::where('code', $expenseCode)->firstOrFail()->id, 'debit_amount' => $amount],
                ['account_id' => Account::where('code', '1100')->firstOrFail()->id, 'credit_amount' => $amount],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    public function test_the_register_stops_where_the_other_reports_stop(): void
    {
        $this->postEntry(now()->subDay()->toDateString(), 92000, 'Rent, already paid');
        $this->postEntry(now()->addDays(3)->toDateString(), 88000, 'Rent, scheduled');

        $rent = Account::where('code', '5700')->firstOrFail();

        $register = app(RegisterEntryService::class)->registerRows($rent, null, now()->toDateString());
        $profitAndLoss = app(FinancialReportService::class)->profitAndLoss(null, now()->toDateString());

        $this->assertSame(92000.0, $register['closing_balance']);
        $this->assertSame(
            92000.0,
            collect($profitAndLoss['expenses']['rows'])->firstWhere('code', '5700')['amount'],
            'the two agree',
        );
    }

    public function test_what_the_end_date_excludes_is_reported(): void
    {
        // Leaving it off the total silently is the whole problem; the register has
        // to say the money is there but later.
        $this->postEntry(now()->subDay()->toDateString(), 92000, 'Rent, already paid');
        $this->postEntry(now()->addDays(3)->toDateString(), 88000, 'Rent, scheduled');
        $this->postEntry(now()->addDays(4)->toDateString(), 10000, 'More rent, scheduled');

        $register = app(RegisterEntryService::class)
            ->registerRows(Account::where('code', '5700')->firstOrFail(), null, now()->toDateString());

        $this->assertSame(2, $register['beyond']['count']);
        $this->assertSame(98000.0, $register['beyond']['total']);
    }

    public function test_nothing_is_reported_when_there_is_nothing_beyond(): void
    {
        $this->postEntry(now()->subDay()->toDateString(), 92000, 'Rent, already paid');

        $register = app(RegisterEntryService::class)
            ->registerRows(Account::where('code', '5700')->firstOrFail(), null, now()->toDateString());

        $this->assertNull($register['beyond']);
    }

    public function test_clearing_the_end_date_brings_them_back(): void
    {
        $this->postEntry(now()->subDay()->toDateString(), 92000, 'Rent, already paid');
        $this->postEntry(now()->addDays(3)->toDateString(), 88000, 'Rent, scheduled');

        $register = app(RegisterEntryService::class)
            ->registerRows(Account::where('code', '5700')->firstOrFail(), null, null);

        $this->assertSame(180000.0, $register['closing_balance']);
        $this->assertNull($register['beyond'], 'nothing is beyond an open end date');
    }

    public function test_the_page_opens_at_today(): void
    {
        // On the day, not on the string: Filament hydrates a date picker's state
        // with a time of day, the same as the Profit & Loss page does.
        Livewire::test(AccountRegister::class)
            ->assertSet('data.to', fn ($value): bool => \Illuminate\Support\Carbon::parse($value)->isToday());
    }

    /** The register is a cash account by default, so the note has to work there too. */
    public function test_the_page_says_what_it_is_leaving_out(): void
    {
        $this->postEntry(now()->addDays(3)->toDateString(), 88000, 'Rent, scheduled');

        Livewire::test(AccountRegister::class)
            ->assertSee('clear the To date to include them');
    }
}
