<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\GeneralLedger;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Filament\Pages\Reports;
use Illuminate\Support\Facades\DB;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The general ledger: every account, its entries in date order, opening → movement → closing.
 *
 * `generalLedger()` existed for months with no caller anywhere in the application, so nothing had ever
 * exercised it at the size a real chart of accounts has. Surfacing it in the Reports explorer meant
 * rewriting how it fetches — three queries per account became three in total — and the whole of this file
 * is about that rewrite being invisible in the figures.
 *
 * The equivalence test carries most of that: the batched result must equal what the per-account method
 * produces, account by account and line by line, with `accountLedger()` as the reference because it is
 * what the single-account register has always used.
 *
 * **What it cannot carry, and this was proved rather than assumed:** the rewrite left both paths sharing
 * one `ledgerFor()`, so a bug in the shared arithmetic makes them agree while both are wrong. Flipping the
 * sign convention deliberately leaves the equivalence test green. The two tests at the bottom of this file
 * are what catch that — an opening balance that must not appear as a line, and a credit-normal account
 * that must climb on a credit — and they are the reason this file does not stop at equivalence.
 */
class GeneralLedgerTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'gl@test.local'));
        $this->setCurrentTenant();
    }

    /** @param array<int, array{0: string, 1: string, 2: float}> $lines */
    private function postEntry(string $date, array $lines): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Ledger fixture'],
            collect($lines)->map(fn (array $line): array => [
                'account_id' => Account::where('code', $line[0])->firstOrFail()->id,
                $line[1] => $line[2],
            ])->all(),
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    /**
     * Movement in three accounts, some of it before the period so the opening balance is exercised.
     *
     * Both sides of the sign convention are covered deliberately: 1100 is an asset (debit-normal) and
     * 4100 and 2100 are credit-normal, so a bug that treated a debit as an increase everywhere would
     * show up here rather than in an account that happens to agree.
     */
    private function fixture(): void
    {
        // Before the period — these become opening balances, never rows.
        $this->postEntry('2025-12-15', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);

        $this->postEntry('2026-02-10', [['1100', 'debit_amount', 120000], ['4100', 'credit_amount', 120000]]);
        $this->postEntry('2026-03-05', [['1100', 'debit_amount', 80000], ['4100', 'credit_amount', 80000]]);
        $this->postEntry('2026-04-20', [['5100', 'debit_amount', 45000], ['2100', 'credit_amount', 45000]]);
    }

    /**
     * The rewrite changed no figure.
     *
     * Every account in the chart, compared whole: opening balance, each line's date, reference, memo,
     * debit, credit and running balance, and the closing balance. Not a spot check — the equivalence *is*
     * the specification, and anything less would let one column drift.
     */
    public function test_the_batched_ledger_equals_the_per_account_ledger(): void
    {
        $this->fixture();

        $ledgers = app(GeneralLedgerService::class)->generalLedger('2026-01-01', '2026-06-30');

        $this->assertNotEmpty($ledgers, 'the fixture produced no ledgers, so this proves nothing');

        foreach ($ledgers as $ledger) {
            $account = Account::where('code', $ledger['account']['code'])->firstOrFail();

            $this->assertEquals(
                app(GeneralLedgerService::class)->accountLedger($account, '2026-01-01', '2026-06-30'),
                $ledger,
                "[{$account->code} {$account->name}] disagrees with its own account ledger",
            );
        }
    }

    /**
     * And it drops the same accounts.
     *
     * The filter is what makes the report readable — a chart carries every account a company might ever
     * use — so an account that would have been dropped before must still be dropped, and one that would
     * have been kept must still be kept. Asserted as a set of codes rather than a count, because two
     * different accounts appearing and disappearing would net to the same count.
     */
    public function test_it_keeps_exactly_the_accounts_with_something_to_show(): void
    {
        $this->fixture();

        $service = app(GeneralLedgerService::class);

        $expected = Account::orderBy('code')->get()
            ->map(fn (Account $account): array => $service->accountLedger($account, '2026-01-01', '2026-06-30'))
            ->filter(fn (array $ledger): bool => $ledger['lines'] !== [] || $ledger['opening_balance'] != 0)
            ->map(fn (array $ledger): string => $ledger['account']['code'])
            ->values()
            ->all();

        $actual = array_map(
            fn (array $ledger): string => $ledger['account']['code'],
            $service->generalLedger('2026-01-01', '2026-06-30'),
        );

        $this->assertSame($expected, $actual);

        // Guards the comparison: an empty expectation would satisfy it.
        $this->assertContains('1100', $actual);

        // An account nothing was posted to, with no opening balance, stays out.
        $this->assertNotContains('1150', $actual, 'an untouched account is being listed');
    }

    /**
     * The cost does not grow with the chart of accounts.
     *
     * This is the reason the rewrite happened, and the assertion that stops it being undone: the
     * per-account loop cost 136 queries against the 44-account seeded chart and would cost triple that
     * against a real one. A ceiling rather than an exact number, because the fixture's own posting is not
     * what is being measured here.
     */
    public function test_it_does_not_query_per_account(): void
    {
        $this->fixture();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(GeneralLedgerService::class)->generalLedger('2026-01-01', '2026-06-30');

        $this->assertGreaterThan(
            20,
            Account::count(),
            'the chart is too small for this test to be evidence of anything',
        );

        $this->assertLessThanOrEqual(
            8,
            count($queries),
            'the general ledger ran '.count($queries)." queries — it is asking per account again:\n\n"
            .implode("\n", $queries),
        );
    }

    /**
     * A balance brought forward is a balance, not a line.
     *
     * The entry posted before the period must move the opening balance and appear nowhere in the rows —
     * a report that listed it would double-count it against the previous period's closing.
     */
    public function test_a_balance_before_the_period_opens_the_ledger_rather_than_appearing_in_it(): void
    {
        $this->fixture();

        $cash = collect(app(GeneralLedgerService::class)->generalLedger('2026-01-01', '2026-06-30'))
            ->firstWhere('account.code', '1100');

        $this->assertNotNull($cash);
        $this->assertSame(500000.0, $cash['opening_balance']);

        foreach ($cash['lines'] as $line) {
            $this->assertGreaterThanOrEqual('2026-01-01', $line['date'], 'a pre-period entry is listed as a line');
        }

        // Opening plus the period's own movement, which is what the closing balance claims to be.
        $this->assertSame(700000.0, $cash['closing_balance']);
    }

    /**
     * The running balance runs in the account's own direction.
     *
     * Income is credit-normal, so its credits increase it. Treating a debit as an increase everywhere is
     * the classic version of this bug, and it produces a ledger that looks orderly and is negative.
     */
    public function test_a_credit_normal_account_increases_on_a_credit(): void
    {
        $this->fixture();

        $income = collect(app(GeneralLedgerService::class)->generalLedger('2026-01-01', '2026-06-30'))
            ->firstWhere('account.code', '4100');

        $this->assertNotNull($income);
        $this->assertSame('credit', $income['account']['normal_balance']);

        // Two credits, so the balance climbs and the closing figure is their sum.
        $this->assertSame([120000.0, 200000.0], array_column($income['lines'], 'balance'));
        $this->assertSame(200000.0, $income['closing_balance']);
    }

    // ------------------------------------------------------------------- the screens

    /**
     * The report's own page renders it.
     *
     * With rows, deliberately: `FilamentReportPagesSmokeTest` creates a user and nothing else, so every
     * report it renders is empty and no per-row branch of the view ever runs. That is what let two 500s
     * through on the payroll-runs and tax-rates lists, and a ledger is nothing but per-row branches.
     */
    public function test_the_page_renders_the_ledger(): void
    {
        $this->fixture();

        \Livewire\Livewire::test(GeneralLedger::class)
            ->assertSuccessful()
            // An account heading, a line against it, and the proof the period balances.
            ->assertSee('1100')
            ->assertSee('Opening 500,000.00')
            ->assertSee('Closing balance')
            ->assertSee('Balanced');
    }

    /**
     * The page opens on the financial year to date, not the calendar year.
     *
     * The mistake this guards is the one ReportPeriod exists for: a ledger opened on 1 January against a
     * year that starts on 1 July silently omits its first six months, and looks entirely normal doing it.
     */
    public function test_the_page_opens_on_the_financial_year(): void
    {
        $page = \Livewire\Livewire::test(GeneralLedger::class)->assertSuccessful();

        // On the date part: the picker keeps a time in its state, which is Filament's business and not
        // what this test is about.
        $this->assertSame(
            $this->fiscalYear->start_date->toDateString(),
            \Carbon\Carbon::parse($page->get('data.from'))->toDateString(),
            'the general ledger opens on the calendar year rather than the fiscal one',
        );
    }

    /** And it is drawn in the Reports explorer, where it is reached from. */
    public function test_the_explorer_draws_it(): void
    {
        $this->fixture();

        \Livewire\Livewire::test(Reports::class)
            ->call('select', 'GeneralLedger')
            ->assertSuccessful()
            ->assertSee('General Ledger')
            // The section heading carries the account and what it opened at.
            ->assertSee('OPENING 500,000')
            ->assertSee('Closing balance');
    }
}
