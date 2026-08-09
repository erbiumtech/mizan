<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Filament\Widgets\ReceivablesPayablesOverview;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The dashboard panel for what is owed, and whether any of it is late.
 *
 * The figures come from the same InvoiceService call the Aged Receivables page
 * renders, so the test that matters is the split the widget adds on top: which
 * invoices count as open, which as overdue, and that the two still add to the
 * total the aged report prints.
 */
class DashboardReceivablesWidgetTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed today, so "overdue" does not depend on the day the suite runs.
        Carbon::setTestNow('2026-06-30');

        $this->actingAs($this->makeUser('Administrator', 'dashboard-ar@test.local'));
        $this->setCurrentTenant();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_BOTH,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function issue(string $kind, float $amount, string $dueDate): Invoice
    {
        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-01-01',
            'due_date' => $dueDate,
            'fiscal_year_id' => $this->fiscalYear->id,
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create([
            'description' => 'Services',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'account_id' => Account::where('code', $kind === Invoice::KIND_SALE ? '4100' : '5700')->firstOrFail()->id,
        ]);

        return app(InvoiceService::class)->issue($invoice->refresh());
    }

    private function panel(string $title): array
    {
        $panels = (new ReceivablesPayablesOverview)->panels();

        return collect($panels)->firstWhere('title', $title);
    }

    public function test_it_splits_what_is_within_terms_from_what_is_late(): void
    {
        $this->issue(Invoice::KIND_SALE, 100000, '2026-07-31'); // a month away
        $this->issue(Invoice::KIND_SALE, 40000, '2026-06-01');  // 29 days late
        $this->issue(Invoice::KIND_SALE, 25000, '2026-01-15');  // long gone

        $panel = $this->panel('Receivables');

        $this->assertSame(165000.0, $panel['total']);
        $this->assertSame(100000.0, $panel['open']);
        $this->assertSame(65000.0, $panel['overdue']);
        $this->assertSame(1, $panel['open_count']);
        $this->assertSame(2, $panel['overdue_count']);
        $this->assertSame(3, $panel['count']);
    }

    public function test_the_split_adds_back_to_the_total_the_aged_report_prints(): void
    {
        $this->issue(Invoice::KIND_SALE, 92000, '2026-05-01');
        $this->issue(Invoice::KIND_SALE, 17500, '2026-08-01');

        $panel = $this->panel('Receivables');
        $report = app(InvoiceService::class)->outstandingReceivables();

        $this->assertSame(
            round($report['total'], 2),
            round($panel['open'] + $panel['overdue'], 2),
            'the dashboard and the report cannot disagree about what is owed'
        );
        $this->assertSame(round($report['total'], 2), $panel['total']);
    }

    public function test_an_invoice_due_today_is_open_not_overdue(): void
    {
        // The boundary: due today is not late, and off-by-one here would put every
        // invoice on its due date into the red.
        $this->issue(Invoice::KIND_SALE, 10000, '2026-06-30');

        $panel = $this->panel('Receivables');

        $this->assertSame(10000.0, $panel['open']);
        $this->assertSame(0.0, $panel['overdue']);
    }

    public function test_shares_are_percentages_of_the_total_and_survive_an_empty_book(): void
    {
        $empty = $this->panel('Payables');

        $this->assertSame(0, $empty['count']);
        $this->assertSame(0.0, $empty['open_share']);
        $this->assertSame(0.0, $empty['overdue_share']);

        $this->issue(Invoice::KIND_PURCHASE, 75000, '2026-06-01');
        $this->issue(Invoice::KIND_PURCHASE, 25000, '2026-12-01');

        $panel = $this->panel('Payables');

        $this->assertSame(75.0, $panel['overdue_share']);
        $this->assertSame(25.0, $panel['open_share']);
    }

    public function test_receivables_and_payables_do_not_bleed_into_each_other(): void
    {
        $this->issue(Invoice::KIND_SALE, 50000, '2026-06-01');
        $this->issue(Invoice::KIND_PURCHASE, 30000, '2026-06-01');

        $this->assertSame(50000.0, $this->panel('Receivables')['total']);
        $this->assertSame(30000.0, $this->panel('Payables')['total']);
    }

    public function test_a_paid_invoice_leaves_the_panel(): void
    {
        $invoice = $this->issue(Invoice::KIND_SALE, 20000, '2026-06-01');

        $this->assertSame(20000.0, $this->panel('Receivables')['total']);

        app(InvoiceService::class)->recordPayment($invoice, 20000, '2026-06-15');

        $this->assertSame(0.0, $this->panel('Receivables')['total']);
        $this->assertSame(0, $this->panel('Receivables')['count']);
    }

    public function test_the_widget_renders_with_and_without_outstanding_invoices(): void
    {
        Livewire::test(ReceivablesPayablesOverview::class)
            ->assertSuccessful()
            ->assertSee('Nothing outstanding');

        $this->issue(Invoice::KIND_SALE, 61000, '2026-02-01');

        Livewire::test(ReceivablesPayablesOverview::class)
            ->assertSuccessful()
            ->assertSee('Overdue')
            ->assertSee('61,000.00');
    }

    /**
     * Registration, not rendering: the Invoicing plugin discovered resources and
     * pages but not widgets, so a widget dropped in this directory was invisible on
     * the dashboard while passing every test that mounted it directly.
     */
    public function test_the_dashboard_actually_shows_it(): void
    {
        $this->issue(Invoice::KIND_SALE, 33000, '2026-02-01');

        $widgets = collect(Filament::getPanel('admin')->getWidgets());

        $this->assertTrue(
            $widgets->contains(ReceivablesPayablesOverview::class),
            'the panel does not know about the widget — check the module plugin discovers Filament/Widgets'
        );

        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertSeeLivewire(ReceivablesPayablesOverview::class);
    }

    public function test_it_is_hidden_when_invoicing_is_switched_off(): void
    {
        $this->assertTrue(ReceivablesPayablesOverview::canView());

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'invoicing'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertFalse(
            ReceivablesPayablesOverview::canView(),
            'a disabled module must not leave its panel on the dashboard'
        );
    }
}
