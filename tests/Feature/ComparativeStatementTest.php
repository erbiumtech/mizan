<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\ComparativeStatement;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The statement the reports explorer draws, and the year beside it.
 *
 * The comparison column is the part worth testing, because it is the part that could be quietly wrong.
 * It is built by asking FinancialReportService for the same statement twelve months earlier and joining
 * the two by account code — so what has to hold is that the join lines up the right figures, that an
 * account which exists in only one of the two years is not silently dropped, and that a percentage is
 * not invented where there is nothing to compare against.
 *
 * What is deliberately *not* tested here is whether the balance sheet is right: BalanceSheetTest owns
 * that, and this class computes no accounting of its own.
 */
class ComparativeStatementTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'compare@test.local'));
        $this->setCurrentTenant();
    }

    private function postEntry(string $date, array $lines): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Comparison fixture'],
            collect($lines)->map(fn (array $line): array => [
                'account_id' => Account::where('code', $line[0])->firstOrFail()->id,
                $line[1] => $line[2],
            ])->all(),
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function statement(string $asOf = '2026-06-30', bool $comparison = true): array
    {
        return app(ComparativeStatement::class)->balanceSheet($asOf, $comparison);
    }

    /** @return array<string, array<string, mixed>> label => row */
    private function rowsOf(array $statement, string $sectionLabel): array
    {
        foreach ($statement['sections'] as $section) {
            if ($section['label'] === $sectionLabel) {
                return collect($section['rows'])->keyBy('label')->all();
            }
        }

        return [];
    }

    // ------------------------------------------------------------- the comparison

    /**
     * The prior column is the same statement a year earlier — not last month, and not the opening
     * balance.
     */
    public function test_the_comparison_column_is_the_same_date_a_year_earlier(): void
    {
        // 400,000 of cash banked in the earlier year, another 100,000 in this one.
        $this->postEntry('2025-03-31', [['1100', 'debit_amount', 400000], ['3100', 'credit_amount', 400000]]);
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 100000], ['3100', 'credit_amount', 100000]]);

        $statement = $this->statement('2026-06-30');
        $cash = $this->rowsOf($statement, 'ASSETS')['Cash / Bank'] ?? null;

        $this->assertNotNull($cash, 'the cash account is missing from assets');
        $this->assertSame(500000.0, $cash['current'], 'the current column is everything to date');
        $this->assertSame(400000.0, $cash['previous'], 'the prior column is the position a year earlier');
        $this->assertSame(25.0, $cash['change'], '500,000 against 400,000 is +25%');

        $this->assertSame('30 Jun 2026', $statement['current_label']);
        $this->assertSame('30 Jun 2025', $statement['previous_label']);
    }

    /** Asked without a comparison, there is no prior column at all — not a column of noughts. */
    public function test_the_comparison_can_be_turned_off(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 100000], ['3100', 'credit_amount', 100000]]);

        $statement = $this->statement('2026-06-30', comparison: false);

        $this->assertNull($statement['previous_label']);
        $this->assertNull($this->rowsOf($statement, 'ASSETS')['Cash / Bank']['previous']);
        $this->assertNull($this->rowsOf($statement, 'ASSETS')['Cash / Bank']['change']);
        $this->assertNull($statement['closing']['previous']);
    }

    /**
     * An account that only exists in one of the two years still appears, with the other side blank.
     *
     * This is the join's real hazard. Keying on the current year alone would drop an account that was
     * used last year and not this one — and in a comparison, an account that has gone quiet is exactly
     * what somebody is looking for.
     */
    public function test_an_account_used_in_only_one_year_is_not_dropped(): void
    {
        // Named from the seeded chart rather than hard-coded: the two seeders that build it have moved a
        // code's name before now, and this test is about the join, not about what 1300 is called.
        $older = Account::where('code', '1300')->value('name');
        $newer = Account::where('code', '1100')->value('name');

        // The older account moved in the earlier year only...
        $this->postEntry('2025-05-31', [['1300', 'debit_amount', 60000], ['3100', 'credit_amount', 60000]]);
        // ...and this one in the later year only.
        $this->postEntry('2026-05-31', [['1100', 'debit_amount', 90000], ['3100', 'credit_amount', 90000]]);

        $assets = $this->rowsOf($this->statement('2026-06-30'), 'ASSETS');

        // A balance carries forward, so the older account is in both columns — and, crucially, it is
        // still listed rather than dropped for having no movement this year.
        $this->assertArrayHasKey($older, $assets, 'an account that has gone quiet vanished');
        $this->assertSame(60000.0, $assets[$older]['previous']);
        $this->assertSame(60000.0, $assets[$older]['current']);

        // The newer one has this year only, and its prior side is blank rather than nought.
        $this->assertArrayHasKey($newer, $assets);
        $this->assertSame(90000.0, $assets[$newer]['current']);
        $this->assertNull($assets[$newer]['previous']);
    }

    /**
     * No percentage where there is nothing to compare against.
     *
     * A first year of trading would otherwise show a change on every line, and "+1,200%" against a
     * rounding remnant reads as a finding when it is an artefact. Null; the view draws a dash.
     */
    public function test_no_change_is_reported_against_a_prior_year_of_nothing(): void
    {
        $this->postEntry('2026-05-31', [['1100', 'debit_amount', 75000], ['3100', 'credit_amount', 75000]]);

        $cash = $this->rowsOf($this->statement('2026-06-30'), 'ASSETS')['Cash / Bank'];

        $this->assertSame(75000.0, $cash['current']);
        $this->assertNull($cash['previous'], 'nothing was posted in the prior year');
        $this->assertNull($cash['change']);
    }

    // ------------------------------------------------------- sections and totals

    /** Sections, each with its own total, and the identity the whole thing has to satisfy. */
    public function test_the_statement_carries_sections_totals_and_the_closing_identity(): void
    {
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 250000], ['3100', 'credit_amount', 250000]]);

        $statement = $this->statement('2026-06-30');

        $this->assertSame(
            ['ASSETS', 'LIABILITIES', 'EQUITY'],
            array_column($statement['sections'], 'label'),
        );

        foreach ($statement['sections'] as $section) {
            $this->assertArrayHasKey('total', $section);
            $this->assertNotEmpty($section['total']['label']);
        }

        // Assets = liabilities + equity, which is what the closing line says.
        $assets = collect($statement['sections'])->firstWhere('label', 'ASSETS')['total']['current'];

        $this->assertSame($assets, $statement['closing']['current']);
        $this->assertTrue($statement['balanced']);
        $this->assertSame('BALANCED · ASSETS = LIABILITIES + EQUITY', $statement['note']);
    }

    /**
     * The period's own profit is in equity.
     *
     * It is a computed line rather than an account, and without it the sections do not add up to what
     * funds the assets — the statement would render as out of balance while the ledger was fine.
     */
    public function test_retained_earnings_for_the_period_appear_in_equity(): void
    {
        // Income of 120,000 banked: assets up, and the profit belongs to equity.
        $this->postEntry('2026-04-30', [['1100', 'debit_amount', 120000], ['4100', 'credit_amount', 120000]]);

        $statement = $this->statement('2026-06-30');
        $equity = $this->rowsOf($statement, 'EQUITY');

        $this->assertArrayHasKey('Retained earnings for the period', $equity);
        $this->assertSame(120000.0, $equity['Retained earnings for the period']['current']);
        $this->assertTrue($statement['balanced']);
    }

    // ---------------------------------------------------------- what it supports

    /** The two statements this shape fits, and an honest no for the rest. */
    public function test_it_says_which_reports_it_can_draw(): void
    {
        $this->assertTrue(ComparativeStatement::supports('BalanceSheet'));
        $this->assertTrue(ComparativeStatement::supports('ProfitAndLoss'));

        // Different shapes — a debit and a credit per row, and a cash flow built from movements rather
        // than accounts. Offered as their own pages instead of rendered wrongly here.
        $this->assertFalse(ComparativeStatement::supports('TrialBalance'));
        $this->assertFalse(ComparativeStatement::supports('CashFlow'));
        $this->assertFalse(ComparativeStatement::supports('SalaryBankFile'));
        $this->assertFalse(ComparativeStatement::supports(null));

        $this->assertNull(app(ComparativeStatement::class)->for('TrialBalance', '2026-06-30'));
    }

    /**
     * The profit and loss covers the *financial* year to date, not the calendar year.
     *
     * The fiscal years here run 1 July to 30 June. Built from `startOfYear()` — 1 January — a statement to
     * 30 June reports six months of trading as twelve, and the figure looks entirely plausible: the income
     * posted in the first half of the year is simply absent. This posts income in August, which only
     * appears if the period starts where the fiscal year does.
     */
    public function test_the_profit_and_loss_covers_the_financial_year_not_the_calendar_year(): void
    {
        // August 2025 falls in FY 2025-2026 (1 Jul 2025 – 30 Jun 2026) and *before* 1 January 2026.
        $this->postEntry('2025-08-31', [['1100', 'debit_amount', 700000], ['4100', 'credit_amount', 700000]]);

        $statement = app(ComparativeStatement::class)->for('ProfitAndLoss', '2026-06-30');

        $income = collect($statement['sections'])->firstWhere('label', 'INCOME')['total'];

        $this->assertSame(700000.0, $income['current'], 'income from the first half of the fiscal year was dropped');
        $this->assertStringContainsString('1 Jul 2025', $statement['subtitle']);
    }

    /** The profit and loss reads the same way, over a range rather than to a date. */
    public function test_the_profit_and_loss_compares_the_same_range_a_year_earlier(): void
    {
        $this->postEntry('2025-03-31', [['1100', 'debit_amount', 200000], ['4100', 'credit_amount', 200000]]);
        $this->postEntry('2026-03-31', [['1100', 'debit_amount', 300000], ['4100', 'credit_amount', 300000]]);

        $statement = app(ComparativeStatement::class)->profitAndLoss('2026-01-01', '2026-06-30');

        $this->assertSame(['INCOME', 'EXPENSES'], array_column($statement['sections'], 'label'));

        $income = collect($statement['sections'])->firstWhere('label', 'INCOME')['total'];

        $this->assertSame(300000.0, $income['current']);
        $this->assertSame(200000.0, $income['previous'], 'the same months of the prior year');
        $this->assertSame(50.0, $income['change']);
        $this->assertSame('Net profit', $statement['closing']['label']);
    }
}
