<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Widgets\CashCommittedOverview;
use App\Modules\Accounting\Filament\Widgets\RevenueAndExpensesChart;
use App\Modules\Core\Filament\Pages\Dashboard;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Filament\Widgets\LargestDebtorsList;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\CashCommitments;
use App\Support\Reporting\DashboardPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The money widgets — `docs/reports-expansion-plan.md` Phase 5.5.
 *
 * **Phase 5's rule is what most of these assert: a widget is fed by the same service as its report.** The
 * plan says why in the section header — "a widget and a report that disagree about a number is worse than
 * either alone, because the person who spots it cannot tell which to believe" — and it warns that
 * re-deriving each figure inline would have been quicker. So the debtor test compares the widget against
 * `InvoiceService::outstandingReceivables()` rather than against a literal, and the chart against
 * `FinancialReportService::profitAndLoss()`.
 *
 * The other thing under test is how each widget reads the dashboard's period, because all three read it
 * differently and each difference is a decision:
 *
 *  - the chart's period chooses where the twelve-month series **ends**, since honouring a one-month period by
 *    drawing one bar would destroy the widget rather than filter it;
 *  - the debtors list treats it as an **as-at**, because ageing is a balance and not a span;
 *  - the commitments widget treats it as the **origin** of a forward ninety days, because the window looks
 *    ahead and narrowing it to a month would answer a question the heading does not claim.
 *
 * The clock is frozen: every one of those is arithmetic on a date.
 */
class MoneyWidgetsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const TODAY = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CashCommitments::flush();

        parent::tearDown();
    }

    // ────────────────────────────────────────────────── fixtures ──

    private function invoice(string $contact, float $total, string $due, float $paid = 0): Invoice
    {
        $party = Contact::firstOrCreate(['name' => $contact], ['kind' => Contact::KIND_CUSTOMER]);

        return Invoice::create([
            'contact_id' => $party->getKey(),
            'kind' => Invoice::KIND_SALE,
            'status' => $paid > 0 ? Invoice::STATUS_PARTIALLY_PAID : Invoice::STATUS_ISSUED,
            'invoice_date' => '2026-10-01',
            'due_date' => $due,
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => $paid,
        ]);
    }

    // ─────────────────── revenue and expenses: twelve months, ending where the period does ──

    /** It renders, and says which twelve months. */
    public function test_the_chart_states_the_twelve_months_it_covers(): void
    {
        Livewire::test(RevenueAndExpensesChart::class, ['periodTo' => '2027-02-19'])
            ->assertSuccessful()
            ->assertSee('Twelve months to February 2027');
    }

    /**
     * The period chooses where the series ends, not how long it is.
     *
     * A one-month period does not make a one-bar chart: twelve months is the shape the widget exists to show,
     * and filtering it to one column would destroy the widget rather than filter it. Reading the dashboard as
     * at last June should give the twelve months to last June, which is a real use of the filter.
     */
    public function test_the_series_is_always_twelve_months_and_ends_where_the_period_does(): void
    {
        $data = $this->chartData(['periodTo' => '2026-09-30']);

        $this->assertCount(12, $data['labels']);
        $this->assertSame('Oct 25', $data['labels'][0]);
        $this->assertSame('Sep 26', $data['labels'][11]);
    }

    /** Both series are drawn, and named. */
    public function test_the_chart_draws_revenue_and_expenses(): void
    {
        $data = $this->chartData();

        $this->assertSame(['Revenue', 'Expenses'], array_column($data['datasets'], 'label'));
        $this->assertCount(12, $data['datasets'][0]['data']);
        $this->assertCount(12, $data['datasets'][1]['data']);
    }

    /**
     * The figures come from the Profit & Loss report's own service.
     *
     * Asserted against `FinancialReportService::profitAndLoss()` rather than against a literal, so the chart
     * and the report cannot drift: if what counts as income changes, this fails.
     */
    public function test_the_chart_agrees_with_the_profit_and_loss_service(): void
    {
        $data = $this->chartData(['periodTo' => '2027-02-19']);

        $february = app(\App\Modules\Accounting\Services\FinancialReportService::class)
            ->profitAndLoss('2027-02-01', '2027-02-19');

        $this->assertSame(
            round((float) $february['income']['total'], 2),
            $data['datasets'][0]['data'][11],
            'the last column is not the same figure the P&L service reports for that month',
        );
    }

    /**
     * The final month is capped at the period's end.
     *
     * Otherwise the last column would be a whole month padded with a future nobody has traded in yet, and it
     * would read as a collapse every time somebody looked early in a month.
     */
    public function test_the_final_month_stops_at_the_period_end(): void
    {
        $this->invoice('Karachi Textiles', 100_000, '2027-02-28');

        $wholeMonth = app(\App\Modules\Accounting\Services\FinancialReportService::class)
            ->profitAndLoss('2027-02-01', '2027-02-28');
        $toDate = app(\App\Modules\Accounting\Services\FinancialReportService::class)
            ->profitAndLoss('2027-02-01', '2027-02-10');

        $data = $this->chartData(['periodTo' => '2027-02-10']);

        $this->assertSame(round((float) $toDate['income']['total'], 2), $data['datasets'][0]['data'][11]);
        $this->assertNotSame($wholeMonth, $toDate, 'the fixture cannot tell the two spans apart');
    }

    /** @return array<string, mixed> */
    private function chartData(array $params = []): array
    {
        $widget = Livewire::test(RevenueAndExpensesChart::class, $params)->assertSuccessful()->instance();

        return (new \ReflectionMethod($widget, 'getData'))->invoke($widget);
    }

    // ─────────────────── largest debtors: by contact, worst days, same service ──

    /**
     * Debtors are aggregated by contact, not listed by invoice.
     *
     * The plan asks for debtors; five largest *invoices* would put one customer in the list three times and
     * answer a different question.
     */
    public function test_debtors_are_aggregated_by_contact(): void
    {
        $this->invoice('Karachi Textiles', 60_000, '2027-01-01');
        $this->invoice('Karachi Textiles', 40_000, '2027-01-15');
        $this->invoice('Lahore Mills', 70_000, '2027-01-10');

        $debtors = $this->debtors();

        $this->assertCount(2, $debtors);
        $this->assertSame('Karachi Textiles', $debtors[0]['contact']);
        $this->assertSame(100_000.0, $debtors[0]['outstanding']);
        $this->assertSame(2, $debtors[0]['invoices']);
    }

    /**
     * Days overdue is the worst of their invoices, not an average.
     *
     * A customer with one invoice ninety days late and nine current ones is a ninety-day problem. Averaging
     * would report them as nine days late, which is the number that gets them left alone.
     */
    public function test_days_overdue_is_the_worst_invoice_not_an_average(): void
    {
        $this->invoice('Karachi Textiles', 10_000, '2026-11-01');
        $this->invoice('Karachi Textiles', 10_000, '2027-02-18');

        $this->assertSame(110, $this->debtors()[0]['days'], '1 Nov to 19 Feb');
    }

    /** Only five, however many owe. A dashboard is not a work queue. */
    public function test_only_five_debtors_are_listed(): void
    {
        foreach (range(1, 8) as $n) {
            $this->invoice('Customer '.$n, $n * 10_000, '2027-01-01');
        }

        $debtors = $this->debtors();

        $this->assertCount(LargestDebtorsList::HOW_MANY, $debtors);
        $this->assertSame('Customer 8', $debtors[0]['contact'], 'largest first');
    }

    /**
     * A contact whose credits cancel their invoices is not a debtor.
     *
     * Receivables include credit notes and subtract them, so a net of nought or below is somebody who owes
     * nothing — and they would otherwise take a place in a list of five from somebody who does.
     */
    public function test_a_contact_who_owes_nothing_is_not_listed(): void
    {
        $this->invoice('Karachi Textiles', 50_000, '2027-01-01');
        $this->invoice('Settled Ltd', 20_000, '2027-01-01', paid: 20_000);

        $debtors = $this->debtors();

        $this->assertCount(1, $debtors);
        $this->assertSame('Karachi Textiles', $debtors[0]['contact']);
    }

    /**
     * The total agrees with the ageing report's own service.
     *
     * The same call, aggregated rather than re-queried — which is Phase 5's rule and the reason the widget
     * does not sum `total - paid` itself.
     */
    public function test_the_debtor_total_agrees_with_the_ageing_service(): void
    {
        $this->invoice('Karachi Textiles', 60_000, '2027-01-01');
        $this->invoice('Lahore Mills', 40_000, '2027-01-01');

        $report = app(\App\Modules\Invoicing\Services\InvoiceService::class)->outstandingReceivables(self::TODAY);

        $this->assertSame(
            round((float) $report['total'], 2),
            round(array_sum(array_column($this->debtors(), 'outstanding')), 2),
        );
    }

    /** The period is an as-at: a dashboard read for last quarter says who owed then. */
    public function test_the_debtors_list_reads_as_at_the_period_end(): void
    {
        $this->invoice('Karachi Textiles', 60_000, '2027-01-01');

        // 1 January to 19 February is 49 days; to 21 January it is 20. The same invoice, two dates.
        $this->assertSame(49, $this->debtors(['periodTo' => '2027-02-19'])[0]['days']);
        $this->assertSame(20, $this->debtors(['periodTo' => '2027-01-21'])[0]['days']);
    }

    /** With nobody owing, it says so rather than showing an empty box. */
    public function test_the_debtors_list_says_when_nobody_owes(): void
    {
        Livewire::test(LargestDebtorsList::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('Nobody owes anything at this date.');
    }

    /** @return array<int, array<string, mixed>> */
    private function debtors(array $params = ['periodTo' => self::TODAY]): array
    {
        return Livewire::test(LargestDebtorsList::class, $params)
            ->assertSuccessful()
            ->instance()
            ->debtors();
    }

    // ─────────────────── cash committed: ninety days from the period's end ──

    /** Commitments out and in are kept apart, with a net. */
    public function test_commitments_are_split_by_direction_with_a_net(): void
    {
        CashCommitments::flush();
        CashCommitments::register('test-out', fn (string $from, string $to): array => [
            ['date' => '2027-03-01', 'kind' => 'Rent', 'description' => 'Office', 'amount' => 120_000, 'direction' => 'out', 'raised' => false],
        ]);
        CashCommitments::register('test-in', fn (string $from, string $to): array => [
            ['date' => '2027-03-05', 'kind' => 'Retainer', 'description' => 'Acme', 'amount' => 200_000, 'direction' => 'in', 'raised' => false],
        ]);

        Livewire::test(CashCommittedOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('120,000')
            ->assertSee('200,000')
            ->assertSee('80,000')
            ->assertSee('covered by what is expected');
    }

    /** More going out than in is said, not left as arithmetic across two stats. */
    public function test_a_shortfall_is_stated(): void
    {
        CashCommitments::flush();
        CashCommitments::register('test-out', fn (string $from, string $to): array => [
            ['date' => '2027-03-01', 'kind' => 'Rent', 'description' => 'Office', 'amount' => 300_000, 'direction' => 'out', 'raised' => false],
        ]);

        Livewire::test(CashCommittedOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('more going out than coming in');
    }

    /**
     * The window runs ninety days **forward from the period's end**.
     *
     * The plan's window looks ahead, so the dashboard's period sets the origin rather than the span: reading
     * as at 30 June answers "what is committed for the ninety days after June", which is the question
     * somebody asks of a year end. The sources are handed those dates, so this asserts what they receive.
     */
    public function test_the_window_runs_ninety_days_from_the_period_end(): void
    {
        CashCommitments::flush();
        $seen = [];
        CashCommitments::register('test-window', function (string $from, string $to) use (&$seen): array {
            $seen = ['from' => $from, 'to' => $to];

            return [];
        });

        Livewire::test(CashCommittedOverview::class, ['periodTo' => '2027-06-30'])->assertSuccessful();

        $this->assertSame(['from' => '2027-06-30', 'to' => '2027-09-28'], $seen);
    }

    /** And the stats name the window, so the figures are never unlabelled. */
    public function test_the_stats_name_the_window(): void
    {
        Livewire::test(CashCommittedOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('19 Feb to 20 May 2027');
    }

    // ─────────────────── the dashboard hands them the period ──

    /**
     * All three are on the dashboard, and all three are handed the page's period.
     *
     * The item's own requirement — "widgets read the page's filter; none of them keeps its own idea of
     * 'now'". A widget that resolved the period itself would be one that could disagree with the one beside
     * it.
     */
    public function test_the_dashboard_hands_the_period_to_the_money_widgets(): void
    {
        $data = Livewire::test(Dashboard::class, ['period' => DashboardPeriod::YEAR_TO_DATE])
            ->assertSuccessful()
            ->instance()
            ->getWidgetData();

        $this->assertSame('2026-07-01', $data['periodFrom']);
        $this->assertSame(self::TODAY, $data['periodTo']);

        foreach ([RevenueAndExpensesChart::class, LargestDebtorsList::class, CashCommittedOverview::class] as $widget) {
            $this->assertTrue(
                property_exists($widget, 'periodTo'),
                class_basename($widget).' cannot be handed the page period',
            );
        }
    }

    /** Every money widget is lazy, so the dashboard renders and the panels fill in — Phase 5.7. */
    public function test_every_money_widget_is_lazy(): void
    {
        foreach ([RevenueAndExpensesChart::class, LargestDebtorsList::class, CashCommittedOverview::class] as $widget) {
            $lazy = new \ReflectionProperty($widget, 'isLazy');

            $this->assertTrue($lazy->getValue(), class_basename($widget).' is not lazy');
        }
    }

    /** And sorted into the money band, ahead of sales, service and people. */
    public function test_the_money_widgets_sort_into_the_money_band(): void
    {
        foreach ([
            RevenueAndExpensesChart::class => 10,
            LargestDebtorsList::class => 11,
            CashCommittedOverview::class => 12,
        ] as $widget => $sort) {
            $this->assertSame($sort, (new \ReflectionProperty($widget, 'sort'))->getValue());
        }
    }

    /** Each gates on its module as well as a permission. */
    public function test_each_widget_is_gated_on_its_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())->update(['enabled' => false]);
        modules()->flush();

        foreach ([RevenueAndExpensesChart::class, LargestDebtorsList::class, CashCommittedOverview::class] as $widget) {
            $this->assertFalse($widget::canView(), class_basename($widget).' renders without its module');
        }
    }
}
