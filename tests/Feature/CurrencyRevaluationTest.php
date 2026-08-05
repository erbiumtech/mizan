<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\CurrencyRevaluation;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\CurrencyRevaluationService;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Filament\Pages\Reports;
use Database\Seeders\CurrencySeeder;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Restating foreign balances at the rate on a date.
 *
 * A EUR account holds euros, and that balance does not move when the rupee does. What
 * moves is the base-currency figure the balance sheet reports, because each posting was
 * translated on its own day and by month end the account's base balance is a sum of
 * historical rates. Revaluation replaces it with one rate: the one on the date.
 *
 * The property everything here is checking: after a revaluation, an account's base
 * balance is exactly its foreign balance times the rate on that date — and the
 * difference is somewhere a reader can see it.
 */
class CurrencyRevaluationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'reval@test.local'));
        $this->setCurrentTenant();

        $this->seed(CurrencySeeder::class);
    }

    private function service(): CurrencyRevaluationService
    {
        return app(CurrencyRevaluationService::class);
    }

    private function rate(float $rate, string $on): ExchangeRate
    {
        return ExchangeRate::create(['currency_code' => 'EUR', 'effective_on' => $on, 'rate' => $rate]);
    }

    private function eurBank(): Account
    {
        return Account::firstOrCreate(
            ['code' => '1105'],
            ['name' => 'EUR Bank', 'type' => 'asset', 'currency_code' => 'EUR'],
        );
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }

    /** Receive euros: the euro amount and the base amount at that day's rate. */
    private function receive(float $euros, string $date): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => 'Fee received in EUR',
        ], [
            [
                'account_id' => $this->eurBank()->id,
                'currency_code' => 'EUR',
                'foreign_debit_amount' => $euros,
            ],
            [
                'account_id' => $this->accountId('4100'),
                'credit_amount' => round($euros * ExchangeRate::for('EUR', $date), 2),
            ],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function unrealised(string $to = '2026-08-31'): float
    {
        $rows = app(FinancialReportService::class)->profitAndLoss('2026-07-01', $to)['income']['rows'];

        return collect($rows)->firstWhere('code', CurrencyRevaluationService::UNREALISED_ACCOUNT_CODE)['amount'] ?? 0.0;
    }

    // ---- What it computes ---------------------------------------------------

    public function test_it_reports_the_gap_between_the_books_and_todays_rate(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $row = $this->service()->preview('2026-07-31')['rows'][0];

        $this->assertSame(1000.0, $row['foreign_balance'], 'the euros it holds, unchanged');
        $this->assertSame(300000.0, $row['base_balance'], 'what the books say, at July 15 rates');
        $this->assertSame(304000.0, $row['translated'], 'what it is worth on July 31');
        $this->assertSame(4000.0, $row['adjustment']);
    }

    public function test_only_accounts_in_another_currency_are_revalued(): void
    {
        // The rupee bank account is not a translation of anything.
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');

        $codes = collect($this->service()->preview('2026-07-31')['rows'])->pluck('code')->all();

        $this->assertSame(['1105'], $codes);
    }

    public function test_an_account_already_at_the_rate_is_listed_with_nothing_to_do(): void
    {
        // Shown rather than omitted: an account missing from a list is indistinguishable
        // from an account nobody looked at.
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');

        $preview = $this->service()->preview('2026-07-20');

        $this->assertCount(1, $preview['rows']);
        $this->assertSame(0.0, $preview['rows'][0]['adjustment']);
        $this->assertFalse($preview['has_adjustment']);
    }

    public function test_an_account_whose_currency_has_no_rate_is_named(): void
    {
        Account::create(['code' => '1106', 'name' => 'CHF Bank', 'type' => 'asset', 'currency_code' => 'CHF']);

        $preview = $this->service()->preview('2026-07-31');

        $this->assertCount(1, $preview['problems']);
        $this->assertStringContainsString('CHF', $preview['problems'][0]);
        $this->assertStringContainsString('no rate', $preview['problems'][0]);
    }

    // ---- What it posts ------------------------------------------------------

    public function test_a_gain_is_posted_to_the_account_and_the_unrealised_line(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $entry = $this->service()->revalue('2026-07-31');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->is_posted);
        $this->assertSame('adjusting', $entry->entry_type);
        $this->assertSame(4000.0, $this->unrealised('2026-07-31'));
        $this->assertSame(
            304000.0,
            $this->service()->baseBalance($this->eurBank(), '2026-07-31'),
            'the account now reads at one rate',
        );
    }

    public function test_a_fall_in_the_rate_is_a_loss(): void
    {
        $this->rate(304, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(290, '2026-07-31');

        $this->service()->revalue('2026-07-31');

        $this->assertSame(-14000.0, $this->unrealised('2026-07-31'), 'a negative income line, which is what a loss is');
        $this->assertSame(290000.0, $this->service()->baseBalance($this->eurBank(), '2026-07-31'));
    }

    /**
     * The adjustment moves the translation, not the euros. If the lines carried a
     * foreign amount the next revaluation would count them as more currency and the
     * account would inflate every month.
     */
    public function test_the_adjustment_does_not_change_what_the_account_holds(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $this->service()->revalue('2026-07-31');

        $this->assertSame(1000.0, $this->service()->foreignBalance($this->eurBank(), '2026-07-31'));
    }

    public function test_the_books_still_balance(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $this->service()->revalue('2026-07-31');

        $reports = app(FinancialReportService::class);
        $this->assertTrue($reports->trialBalance('2026-07-31')['balanced']);
        $this->assertTrue($reports->balanceSheet('2026-07-31')['balanced']);
    }

    public function test_nothing_is_posted_when_nothing_has_moved(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');

        $this->assertNull($this->service()->revalue('2026-07-20'));
    }

    /** The property that makes it safe to run from a cron job or twice by hand. */
    public function test_running_it_again_on_the_same_date_posts_nothing(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $this->service()->revalue('2026-07-31');

        $this->assertNull($this->service()->revalue('2026-07-31'));
        $this->assertSame(4000.0, $this->unrealised('2026-07-31'), 'and the gain is not doubled');
    }

    public function test_each_month_adjusts_only_what_moved_since_the_last_one(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');

        $this->rate(304, '2026-07-31');
        $this->service()->revalue('2026-07-31');

        $this->rate(310, '2026-08-31');
        $this->service()->revalue('2026-08-31');

        $this->assertSame(310000.0, $this->service()->baseBalance($this->eurBank(), '2026-08-31'));
        $this->assertSame(10000.0, $this->unrealised('2026-08-31'), 'the year to date: 4,000 in July and 6,000 in August');
    }

    /**
     * Each adjustment is the gap left by the ones before it, so they compose in date
     * order and only forwards.
     */
    public function test_revaluing_a_date_before_the_last_revaluation_is_refused(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-08-31');
        $this->service()->revalue('2026-08-31');

        $this->expectExceptionMessage('already been revalued as at 2026-08-31');

        $this->service()->revalue('2026-07-31');
    }

    public function test_a_transaction_backdated_into_a_revalued_month_is_picked_up_next_time(): void
    {
        // Rather than lost: the adjustment is computed from the balance, not from a
        // record of what was adjusted last time.
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');
        $this->service()->revalue('2026-07-31');

        $this->receive(500, '2026-07-20');

        $this->rate(304, '2026-08-31');
        $this->service()->revalue('2026-08-31');

        $this->assertSame(1500.0, $this->service()->foreignBalance($this->eurBank(), '2026-08-31'));
        $this->assertSame(456000.0, $this->service()->baseBalance($this->eurBank(), '2026-08-31'));
    }

    public function test_the_entry_says_what_was_translated_at_what_rate(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $entry = $this->service()->revalue('2026-07-31');

        $this->assertStringContainsString('Currency revaluation as at 2026-07-31', $entry->memo);
        $this->assertStringContainsString('EUR 1,000.00 at 304.0000', $entry->lines()->first()->description);
    }

    public function test_the_difference_is_reported_apart_from_realised_gains(): void
    {
        // No money has moved and none will until the balance is settled, so it does not
        // belong with the gains that were actually banked.
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $this->service()->revalue('2026-07-31');

        $this->assertSame(0.0, (float) JournalEntryLine::where(
            'account_id',
            $this->accountId(CurrencyRevaluationService::REALISED_ACCOUNT_CODE),
        )->sum('credit_amount'));
    }

    public function test_two_foreign_accounts_are_one_entry(): void
    {
        // It is a single act on a single date and should read that way in the register.
        $second = Account::create([
            'code' => '1107', 'name' => 'EUR Savings', 'type' => 'asset', 'currency_code' => 'EUR',
        ]);

        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');

        $entries = app(JournalEntryService::class);
        $entry = $entries->create([
            'entry_date' => '2026-07-16', 'entry_type' => 'general', 'memo' => 'Transfer',
        ], [
            ['account_id' => $second->id, 'currency_code' => 'EUR', 'foreign_debit_amount' => 200],
            ['account_id' => $this->accountId('4100'), 'credit_amount' => 60000],
        ]);
        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $entries->post($entry);

        $this->rate(304, '2026-07-31');

        $revaluation = $this->service()->revalue('2026-07-31');

        $this->assertSame(3, $revaluation->lines()->count(), 'two accounts and one unrealised line');
        $this->assertSame(4800.0, $this->unrealised('2026-07-31'));
    }

    // ---- The page -----------------------------------------------------------

    public function test_the_page_shows_what_would_be_posted(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        Livewire::test(CurrencyRevaluation::class)
            ->set('data.as_of', '2026-07-31')
            ->assertSee('EUR Bank')
            ->assertSee('300,000.00')
            ->assertSee('304,000.00')
            ->assertSee('4,000.00');
    }

    public function test_the_page_posts_it(): void
    {
        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        Livewire::test(CurrencyRevaluation::class)
            ->set('data.as_of', '2026-07-31')
            ->callAction('revalue');

        $this->assertSame(4000.0, $this->unrealised('2026-07-31'));
    }

    public function test_the_page_says_when_no_account_is_in_another_currency(): void
    {
        // Rather than an empty table, which reads as a failure.
        Livewire::test(CurrencyRevaluation::class)
            ->assertSee('No account is denominated in another currency');
    }

    public function test_it_is_reachable_from_the_reports_hub(): void
    {
        // A page hidden from the sidebar and missing from the hub is reachable by
        // nothing but its own URL.
        $this->assertContains(
            CurrencyRevaluation::class,
            Reports::linkedPages(),
        );
    }

    public function test_it_refuses_rather_than_inventing_an_account(): void
    {
        Account::where('code', CurrencyRevaluationService::UNREALISED_ACCOUNT_CODE)->delete();

        $this->rate(300, '2026-07-01');
        $this->receive(1000, '2026-07-15');
        $this->rate(304, '2026-07-31');

        $this->expectExceptionMessage('no account 4400');

        $this->service()->revalue('2026-07-31');
    }
}
