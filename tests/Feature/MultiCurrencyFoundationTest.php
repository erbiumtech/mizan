<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\Money;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Money in more than one currency, in the ledger.
 *
 * The decision the whole feature rests on: debit_amount and credit_amount stay the
 * *base* currency, always. Every report in the application reads those two columns and
 * none of them know about currencies, so they keep working untouched. The foreign amount
 * and the rate sit alongside.
 *
 * The alternative — letting those columns mean whichever currency the account is in —
 * would put a mixture of currencies into every SUM() in the codebase, and every one
 * would keep returning a number. That is how this goes subtly wrong, which is exactly
 * what the gap plan warned about, so these tests are mostly about what has *not*
 * changed.
 */
class MultiCurrencyFoundationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'currency@test.local'));
        $this->setCurrentTenant();

        $this->seed(\Database\Seeders\CurrencySeeder::class);
    }

    private function rate(float $rate, string $on = '2026-08-01'): ExchangeRate
    {
        return ExchangeRate::create(['currency_code' => 'EUR', 'effective_on' => $on, 'rate' => $rate]);
    }

    private function postEntry(array $lines, string $date = '2026-08-04'): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Test'], $lines);
        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }

    // ---- Currencies and rates ----------------------------------------------

    public function test_there_is_one_base_currency(): void
    {
        $this->assertSame('PKR', Currency::baseCode());
        $this->assertSame(1, Currency::where('is_base', true)->count());
    }

    public function test_naming_a_second_base_stands_the_first_down(): void
    {
        Currency::where('code', 'EUR')->first()->update(['is_base' => true]);

        $this->assertSame('EUR', Currency::baseCode());
        $this->assertSame(1, Currency::where('is_base', true)->count());
    }

    /**
     * Every amount already posted is recorded in the base currency, so changing which
     * currency that is would not restate those figures — it would reinterpret them. A
     * balance of 6,140,253 would stop meaning rupees without a single row changing.
     */
    public function test_the_base_cannot_be_changed_once_anything_is_posted(): void
    {
        $this->rate(304);
        $this->postEntry([
            ['account_id' => $this->accountId('1100'), 'debit_amount' => 1000],
            ['account_id' => $this->accountId('3100'), 'credit_amount' => 1000],
        ]);

        $this->expectExceptionMessage('reinterpret those figures');

        Currency::where('code', 'PKR')->first()->update(['is_base' => false]);
    }

    public function test_a_rate_is_the_most_recent_on_or_before_the_date(): void
    {
        // An invoice issued in July is worth what it was worth in July; re-translating
        // it whenever somebody adds a rate would rewrite history.
        $this->rate(300, '2026-07-01');
        $this->rate(304, '2026-08-01');
        $this->rate(310, '2026-09-01');

        $this->assertSame(300.0, ExchangeRate::for('EUR', '2026-07-15'));
        $this->assertSame(304.0, ExchangeRate::for('EUR', '2026-08-15'));
        $this->assertSame(310.0, ExchangeRate::for('EUR', '2026-09-15'));
    }

    public function test_a_date_before_any_rate_has_none(): void
    {
        // A real answer that has to be handled: posting at a guessed rate is how a book
        // goes quietly wrong.
        $this->rate(304, '2026-08-01');

        $this->assertNull(ExchangeRate::for('EUR', '2026-07-15'));
    }

    public function test_a_rate_of_zero_or_less_is_refused(): void
    {
        $this->expectExceptionMessage('greater than zero');

        ExchangeRate::create(['currency_code' => 'EUR', 'effective_on' => '2026-08-01', 'rate' => 0]);
    }

    public function test_one_rate_per_currency_per_day(): void
    {
        // A day with two rates is a question nobody can answer later.
        $this->rate(304, '2026-08-01');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->rate(310, '2026-08-01');
    }

    // ---- Conversion ---------------------------------------------------------

    public function test_base_is_foreign_times_the_rate(): void
    {
        // Easy to get backwards, which is why it lives in one place.
        $this->rate(304);

        $this->assertSame(30400.0, Money::toBase(100, 'EUR', '2026-08-04')['base']);
    }

    public function test_the_base_currency_converts_to_itself(): void
    {
        $converted = Money::toBase(1000, 'PKR');

        $this->assertSame(1000.0, $converted['base']);
        $this->assertSame(1.0, $converted['rate']);
    }

    public function test_an_amount_with_no_rate_available_is_refused(): void
    {
        $this->expectExceptionMessage('No exchange rate for EUR');

        Money::toBase(100, 'EUR', '2026-08-04');
    }

    public function test_an_agreed_rate_wins_over_the_table(): void
    {
        // An invoice raised at a rate agreed with the client keeps that rate.
        $this->rate(304);

        $this->assertSame(31000.0, Money::toBase(100, 'EUR', '2026-08-04', rate: 310)['base']);
    }

    // ---- What has not changed ----------------------------------------------

    /** Every existing caller passes base amounts and is untouched. */
    public function test_a_base_currency_entry_posts_exactly_as_before(): void
    {
        $entry = $this->postEntry([
            ['account_id' => $this->accountId('1100'), 'debit_amount' => 500000],
            ['account_id' => $this->accountId('3100'), 'credit_amount' => 500000],
        ]);

        $line = $entry->lines()->first();

        $this->assertNull($line->currency_code);
        $this->assertNull($line->rate);
        $this->assertTrue(app(FinancialReportService::class)->trialBalance('2026-08-31')['balanced']);
    }

    public function test_a_foreign_line_stores_both_amounts_and_its_rate(): void
    {
        $this->rate(304);

        $entry = $this->postEntry([
            [
                'account_id' => $this->accountId('1100'),
                'currency_code' => 'EUR',
                'foreign_debit_amount' => 100,
            ],
            ['account_id' => $this->accountId('3100'), 'credit_amount' => 30400],
        ]);

        $line = $entry->lines()->where('currency_code', 'EUR')->first();

        $this->assertSame('EUR', $line->currency_code);
        $this->assertSame(100.0, round((float) $line->foreign_debit_amount, 2));
        $this->assertSame(30400.0, round((float) $line->debit_amount, 2), 'the base amount the reports read');
        $this->assertSame(304.0, round((float) $line->rate, 2), 'kept, so the line can explain itself');
    }

    /**
     * The property that makes this safe: what balances is the base currency. A EUR line
     * against a PKR line will never balance as written, and both in base always can.
     */
    public function test_a_mixed_currency_entry_balances_in_the_base(): void
    {
        $this->rate(304);

        $entry = $this->postEntry([
            ['account_id' => $this->accountId('1100'), 'currency_code' => 'EUR', 'foreign_debit_amount' => 100],
            ['account_id' => $this->accountId('4100'), 'credit_amount' => 30400],
        ]);

        $this->assertTrue($entry->isBalanced());

        $reports = app(FinancialReportService::class);
        $this->assertTrue($reports->trialBalance('2026-08-31')['balanced']);
        $this->assertTrue($reports->balanceSheet('2026-08-31')['balanced']);
    }

    public function test_the_reports_read_the_base_amount(): void
    {
        // Not the foreign one. A trial balance in a mixture of currencies would still
        // add up to a number, which is the failure this design exists to prevent.
        $this->rate(304);

        $this->postEntry([
            ['account_id' => $this->accountId('1100'), 'currency_code' => 'EUR', 'foreign_debit_amount' => 100],
            ['account_id' => $this->accountId('4100'), 'credit_amount' => 30400],
        ]);

        $this->assertSame(30400.0, app(FinancialReportService::class)->cashAt('2026-08-31'));
    }

    public function test_an_entry_dated_before_any_rate_is_refused_rather_than_guessed(): void
    {
        $this->rate(304, '2026-08-01');

        $this->expectExceptionMessage('No exchange rate for EUR');

        $this->postEntry([
            ['account_id' => $this->accountId('1100'), 'currency_code' => 'EUR', 'foreign_debit_amount' => 100],
            ['account_id' => $this->accountId('4100'), 'credit_amount' => 30400],
        ], date: '2026-07-15');
    }

    public function test_a_settled_base_amount_is_not_recomputed(): void
    {
        // A bank advice saying what actually landed is a fact; the rate table is an
        // estimate of it, and the fact wins.
        $this->rate(304);

        $entry = $this->postEntry([
            [
                'account_id' => $this->accountId('1100'),
                'currency_code' => 'EUR',
                'foreign_debit_amount' => 100,
                'debit_amount' => 30250,
            ],
            ['account_id' => $this->accountId('4100'), 'credit_amount' => 30250],
        ]);

        $line = $entry->lines()->where('currency_code', 'EUR')->first();

        $this->assertSame(30250.0, round((float) $line->debit_amount, 2));
        $this->assertSame(100.0, round((float) $line->foreign_debit_amount, 2));
    }

    public function test_an_account_can_be_denominated_in_a_currency(): void
    {
        $account = Account::create([
            'code' => '1105',
            'name' => 'EUR Bank',
            'type' => 'asset',
            'currency_code' => 'EUR',
        ]);

        $this->assertSame('EUR', $account->fresh()->currency_code);
    }

    public function test_money_formats_with_the_currencys_own_symbol(): void
    {
        $this->assertSame('€ 1,234.56', Money::format(1234.56, 'EUR'));
        $this->assertSame('Rs 1,234.56', Money::format(1234.56, 'PKR'));
    }
}
