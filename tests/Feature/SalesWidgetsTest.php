<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Dashboard;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Filament\Widgets\ForecastAgainstTargetOverview;
use App\Modules\Crm\Filament\Widgets\PipelineFunnelChart;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Services\PipelineReports;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Quotations\Filament\Widgets\QuotationsExpiringList;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use App\Support\Reporting\DashboardPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The sales widgets — `docs/reports-expansion-plan.md` Phase 5.3.
 *
 * As with the money group, the assertions are mostly about Phase 5's rule — a widget is fed by the same
 * service as its report — and about how each widget reads the dashboard's period. Here the three readings are
 * a *window*, an *as-at* and an *origin*, and the funnel is the one widget on the dashboard that ignores the
 * period altogether:
 *
 *  - the **funnel** is unwindowed, because `PipelineReports::byStage()`'s own comment says a deal with no
 *    expected close date "is not closing outside the window, it is unforecastable" — filtering a funnel by
 *    the period would silently drop every deal nobody has dated;
 *  - the **forecast** takes the period as a window, which is what a forecast is;
 *  - **attainment** takes the period's end as an as-at, because a target is a period of its own and asking
 *    which targets are live is a question about a moment.
 */
class SalesWidgetsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const TODAY = '2027-02-19';

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['crm', 'quotations', 'invoicing', 'employees'] as $module) {
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

        $this->pipeline = Pipeline::create(['name' => 'Standard', 'is_default' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ────────────────────────────────────────────────── fixtures ──

    private function stage(string $name, int $probability, int $order): PipelineStage
    {
        return PipelineStage::create([
            'pipeline_id' => $this->pipeline->getKey(),
            'name' => $name,
            'probability' => $probability,
            'sort_order' => $order,
        ]);
    }

    /**
     * A deal.
     *
     * `probability_pct` is set explicitly and not inherited from the stage, because `weightedAmount()` reads
     * the *deal's* percentage — the stage's is the default a user is offered, not the figure the forecast
     * uses. A fixture that left it null weighted every deal at nought, which made a forecast test pass for
     * the wrong reason until it asserted an amount.
     */
    private function deal(
        PipelineStage $stage,
        float $amount,
        ?string $closing = null,
        int $probability = 50,
    ): Opportunity {
        // A contact, because `Opportunity` refuses a deal with neither lead nor customer — "one with neither
        // is a deal about nobody". Worth honouring rather than working around: the widget reads real deals.
        $contact = Contact::firstOrCreate(['name' => 'Karachi Textiles'], ['kind' => Contact::KIND_CUSTOMER]);

        return Opportunity::create([
            'title' => 'Deal '.fake()->unique()->randomNumber(5),
            'pipeline_id' => $this->pipeline->getKey(),
            'pipeline_stage_id' => $stage->getKey(),
            'contact_id' => $contact->getKey(),
            'amount' => $amount,
            'probability_pct' => $probability,
            'expected_close_on' => $closing,
        ]);
    }

    private function quotation(string $number, float $total, ?string $validUntil, string $status = Quotation::STATUS_SENT): Quotation
    {
        $contact = Contact::firstOrCreate(['name' => 'Karachi Textiles'], ['kind' => Contact::KIND_CUSTOMER]);

        return Quotation::create([
            'number' => $number,
            'contact_id' => $contact->getKey(),
            'issue_date' => '2027-02-01',
            'valid_until' => $validUntil,
            'status' => $status,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    // ─────────────────── the funnel ──

    /** Stages become columns, with weighted first because a forecast is built from it. */
    public function test_the_funnel_draws_a_column_per_stage(): void
    {
        $this->deal($this->stage('Qualifying', 20, 1), 100_000);
        $this->deal($this->stage('Proposal', 50, 2), 200_000);

        $data = $this->funnelData();

        $this->assertSame(['Qualifying', 'Proposal'], $data['labels']);
        $this->assertSame(['Weighted', 'Value'], array_column($data['datasets'], 'label'));
    }

    /**
     * The figures are the pipeline report's own.
     *
     * Asserted against `PipelineReports::byStage()` rather than a literal, so the chart and the Pipeline by
     * Stage report cannot drift — if what counts as an open deal changes, this fails.
     */
    public function test_the_funnel_agrees_with_the_pipeline_service(): void
    {
        $this->deal($this->stage('Qualifying', 20, 1), 100_000);

        $rows = app(PipelineReports::class)->byStage($this->pipeline->fresh('stages'));
        $data = $this->funnelData();

        $this->assertSame(
            array_map(fn (array $row): float => $row['weighted'], $rows),
            $data['datasets'][0]['data'],
        );
    }

    /**
     * The funnel ignores the dashboard's period, and a dated deal proves it.
     *
     * `byStage()`'s own comment is the reason: a deal with no expected close date is not closing outside the
     * window, it is unforecastable. A funnel is everything in play, so a period filter would drop exactly the
     * deals nobody has put a date on — the ones most in need of attention.
     */
    public function test_the_funnel_ignores_the_period(): void
    {
        $stage = $this->stage('Qualifying', 20, 1);
        $this->deal($stage, 100_000, closing: '2028-01-01');
        $this->deal($stage, 50_000, closing: null);

        // A one-month period that contains neither deal's close date.
        $data = $this->funnelData(['periodFrom' => '2027-02-01', 'periodTo' => '2027-02-19']);

        $this->assertSame([150_000.0], array_map(
            fn (float $v): float => $v,
            $data['datasets'][1]['data'],
        ), 'the funnel dropped deals the period does not cover');
    }

    /** With no pipeline it says so rather than rendering an empty chart. */
    public function test_the_funnel_says_when_there_is_no_pipeline(): void
    {
        Pipeline::query()->delete();

        Livewire::test(PipelineFunnelChart::class)
            ->assertSuccessful()
            ->assertSee('No pipeline has been set up yet.');
    }

    /** @return array<string, mixed> */
    private function funnelData(array $params = []): array
    {
        $widget = Livewire::test(PipelineFunnelChart::class, $params)->assertSuccessful()->instance();

        return (new \ReflectionMethod($widget, 'getData'))->invoke($widget);
    }

    // ─────────────────── forecast against target ──

    /**
     * The forecast covers the period as a window.
     *
     * The widget the period matters most to: "this month" and "financial year to date" are genuinely
     * different questions about the same pipeline.
     */
    public function test_the_forecast_covers_the_period_as_a_window(): void
    {
        $stage = $this->stage('Proposal', 50, 1);
        $this->deal($stage, 200_000, closing: '2027-02-10');
        $this->deal($stage, 400_000, closing: '2027-08-10');

        // February only: one deal, weighted at 50%.
        Livewire::test(ForecastAgainstTargetOverview::class, [
            'periodFrom' => '2027-02-01',
            'periodTo' => '2027-02-28',
        ])
            ->assertSuccessful()
            ->assertSee('100,000')
            ->assertSee('1 open deals');
    }

    /**
     * And it agrees with the Sales Forecast report's service.
     *
     * Same call, same window — so the widget cannot say one thing while the report says another.
     */
    public function test_the_forecast_agrees_with_the_pipeline_service(): void
    {
        $stage = $this->stage('Proposal', 50, 1);
        $this->deal($stage, 200_000, closing: '2027-02-10');

        $service = app(PipelineReports::class)->forecast('2027-02-01', '2027-02-28');

        Livewire::test(ForecastAgainstTargetOverview::class, [
            'periodFrom' => '2027-02-01',
            'periodTo' => '2027-02-28',
        ])
            ->assertSuccessful()
            ->assertSee(number_format($service['weighted'], 0));
    }

    /**
     * A won deal is not in the forecast, which is the service's own rule.
     *
     * "A won deal is not a forecast — it is an invoice waiting to be raised, and counting it here would
     * double it against whatever Invoicing already says." Worth a test on the widget too, because the
     * widget is where somebody would notice the doubling.
     */
    public function test_a_won_deal_is_not_forecast(): void
    {
        $stage = $this->stage('Proposal', 50, 1);
        $deal = $this->deal($stage, 200_000, closing: '2027-02-10');
        $deal->update(['outcome' => Opportunity::OUTCOME_WON, 'closed_on' => '2027-02-11']);

        Livewire::test(ForecastAgainstTargetOverview::class, [
            'periodFrom' => '2027-02-01',
            'periodTo' => '2027-02-28',
        ])
            ->assertSuccessful()
            ->assertSee('0 open deals');
    }

    /** With no targets it says nobody has one, rather than reporting nought per cent. */
    public function test_with_no_targets_it_says_so(): void
    {
        Livewire::test(ForecastAgainstTargetOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('nobody has a target with a number on it');
    }

    /** A single currency is stated as such, so the total is not read as a conversion. */
    public function test_a_single_currency_forecast_says_no_conversion(): void
    {
        $this->deal($this->stage('Proposal', 50, 1), 200_000, closing: '2027-02-10');

        Livewire::test(ForecastAgainstTargetOverview::class, [
            'periodFrom' => '2027-02-01',
            'periodTo' => '2027-02-28',
        ])
            ->assertSuccessful()
            ->assertSee('no conversion in this figure');
    }

    // ─────────────────── quotations expiring ──

    /** Quotations lapsing inside the fortnight are listed, soonest first. */
    public function test_quotations_expiring_inside_the_fortnight_are_listed(): void
    {
        $this->quotation('Q-2', 50_000, '2027-02-25');
        $this->quotation('Q-1', 30_000, '2027-02-20');

        $rows = $this->expiring();

        $this->assertSame(['Q-1', 'Q-2'], array_column($rows, 'number'));
        $this->assertSame(1, $rows[0]['days']);
        $this->assertSame(6, $rows[1]['days']);
    }

    /** One lapsing beyond the fortnight is not. */
    public function test_a_quotation_beyond_the_fortnight_is_not_listed(): void
    {
        $this->quotation('Q-1', 30_000, '2027-03-20');

        $this->assertSame([], $this->expiring());
    }

    /**
     * One lapsing today is listed, and said to lapse today.
     *
     * The most urgent of the set. An exclusive lower bound would drop exactly the row somebody needs, and
     * "lapses in 0 days" reads as a rounding rather than as today.
     */
    public function test_a_quotation_lapsing_today_is_listed(): void
    {
        $this->quotation('Q-1', 30_000, self::TODAY);

        $this->assertSame(0, $this->expiring()[0]['days']);

        Livewire::test(QuotationsExpiringList::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('lapses today');
    }

    /**
     * Only sent quotations. A draft has not been offered and an answered one has had its answer.
     *
     * The same three conditions `expireLapsed()` applies, which is why the query lives in the service.
     */
    public function test_only_sent_quotations_are_listed(): void
    {
        $this->quotation('Q-draft', 10_000, '2027-02-20', Quotation::STATUS_DRAFT);
        $this->quotation('Q-accepted', 10_000, '2027-02-21', Quotation::STATUS_ACCEPTED);
        $this->quotation('Q-declined', 10_000, '2027-02-22', Quotation::STATUS_DECLINED);
        $this->quotation('Q-sent', 10_000, '2027-02-23');

        $this->assertSame(['Q-sent'], array_column($this->expiring(), 'number'));
    }

    /** A quotation with no validity date cannot lapse and is not listed. */
    public function test_a_quotation_with_no_validity_is_not_listed(): void
    {
        $this->quotation('Q-1', 30_000, null);

        $this->assertSame([], $this->expiring());
    }

    /**
     * The window counts forward from the period's end, not from today.
     *
     * The same reading `CashCommittedOverview` takes of the same kind of question: a forward window means the
     * period sets the origin, not the span.
     */
    public function test_the_window_counts_forward_from_the_period_end(): void
    {
        $this->quotation('Q-1', 30_000, '2027-06-05');

        $this->assertSame([], $this->expiring(), 'June is not within a fortnight of February');
        $this->assertSame(['Q-1'], array_column($this->expiring(['periodTo' => '2027-06-01']), 'number'));
    }

    /** And the service agrees, which is where the query lives. */
    public function test_the_widget_agrees_with_the_quotation_service(): void
    {
        $this->quotation('Q-1', 30_000, '2027-02-20');
        $this->quotation('Q-2', 50_000, '2027-03-20');

        $service = app(QuotationService::class)->expiringWithin(QuotationsExpiringList::DAYS, self::TODAY);

        $this->assertSame(
            $service->pluck('number')->all(),
            array_column($this->expiring(), 'number'),
        );
    }

    /** With nothing lapsing it says so. */
    public function test_it_says_when_nothing_is_lapsing(): void
    {
        Livewire::test(QuotationsExpiringList::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('No quotation lapses in the next');
    }

    /** @return array<int, array<string, mixed>> */
    private function expiring(array $params = ['periodTo' => self::TODAY]): array
    {
        return Livewire::test(QuotationsExpiringList::class, $params)
            ->assertSuccessful()
            ->instance()
            ->quotations();
    }

    // ─────────────────── the group's own rules ──

    /** All three are handed the page's period, lazy, and in the sales band. */
    public function test_the_sales_widgets_follow_the_dashboard_rules(): void
    {
        $data = Livewire::test(Dashboard::class, ['period' => DashboardPeriod::THIS_MONTH])
            ->assertSuccessful()
            ->instance()
            ->getWidgetData();

        $this->assertSame('2027-02-01', $data['periodFrom']);

        foreach ([
            PipelineFunnelChart::class => 20,
            ForecastAgainstTargetOverview::class => 21,
            QuotationsExpiringList::class => 22,
        ] as $widget => $sort) {
            $this->assertTrue(property_exists($widget, 'periodTo'), class_basename($widget).' takes no period');
            $this->assertTrue((new \ReflectionProperty($widget, 'isLazy'))->getValue(), class_basename($widget).' is not lazy');
            $this->assertSame($sort, (new \ReflectionProperty($widget, 'sort'))->getValue());
        }
    }

    /** Each gates on its module. */
    public function test_each_widget_is_gated_on_its_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())->update(['enabled' => false]);
        modules()->flush();

        foreach ([PipelineFunnelChart::class, ForecastAgainstTargetOverview::class, QuotationsExpiringList::class] as $widget) {
            $this->assertFalse($widget::canView(), class_basename($widget).' renders without its module');
        }
    }
}
