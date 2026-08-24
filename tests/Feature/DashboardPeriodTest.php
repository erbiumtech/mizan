<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Dashboard;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Support\Reporting\DashboardPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The dashboard's period filter — `docs/reports-expansion-plan.md` Phase 5.1.
 *
 * Three things are worth testing here and only one of them is the picker:
 *
 *  - **every period ends today.** A dashboard answers "how are we doing", and nobody has revenue from the
 *    rest of the month — so "this quarter" is the quarter so far, not a quarter two thirds empty.
 *  - **quarters are fiscal, not calendar.** The year runs 1 July to 30 June, so its quarters begin in July,
 *    October, January and April. A dashboard calling January–March "this quarter" while the accounts call it
 *    Q3 would have two screens using one word for two spans.
 *  - **widgets are handed the resolved dates**, not the period name, so no widget has to know either of the
 *    above — which is the item's actual requirement: "none of them keeps its own idea of 'now'".
 *
 * The clock is frozen throughout: a period filter is arithmetic on today, and a test whose expectations drift
 * with the wall clock passes in February and fails in March.
 */
class DashboardPeriodTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** A Friday in the third quarter of a July-starting financial year. */
    private const TODAY = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────── which periods there are ──

    /** Four periods, narrowest first — this month is what somebody opens the dashboard for. */
    public function test_the_periods_are_offered_narrowest_first(): void
    {
        $this->assertSame([
            DashboardPeriod::THIS_MONTH,
            DashboardPeriod::THIS_QUARTER,
            DashboardPeriod::YEAR_TO_DATE,
            DashboardPeriod::CUSTOM,
        ], array_keys(DashboardPeriod::PERIODS));
    }

    /**
     * Anything unrecognised becomes this month, not a custom range.
     *
     * A custom range with nothing in it is a dashboard with no period at all, and the commonest way to arrive
     * with a bad value is an old link.
     */
    public function test_an_unknown_period_becomes_this_month(): void
    {
        foreach ([null, '', 'last_fortnight', 'CUSTOM '] as $bad) {
            $this->assertSame(DashboardPeriod::THIS_MONTH, DashboardPeriod::normalise($bad));
        }
    }

    // ─────────────────────────────── the ranges ──

    /** This month starts on the first and ends today. */
    public function test_this_month_runs_from_the_first_to_today(): void
    {
        $this->assertSame(
            ['from' => '2027-02-01', 'to' => self::TODAY],
            DashboardPeriod::range(DashboardPeriod::THIS_MONTH),
        );
    }

    /**
     * This quarter is the **fiscal** quarter containing today, and it ends today.
     *
     * February is in the January–March quarter of a July-starting year, which is that year's third. A
     * calendar reading would give the same months here by coincidence, so the next test uses a year where it
     * does not.
     */
    public function test_this_quarter_runs_from_the_fiscal_quarter_start_to_today(): void
    {
        $this->assertSame(
            ['from' => '2027-01-01', 'to' => self::TODAY],
            DashboardPeriod::range(DashboardPeriod::THIS_QUARTER),
        );
    }

    /**
     * A **short first year** still has the standard quarters, which is the case that corrected the code.
     *
     * `FiscalYear` enforces a 30 June end — "a company joining part-way through gets a shorter year ending on
     * the same date" — so a company that joined in November runs 1 November to 30 June. Counting three-month
     * blocks forward from that start would give it quarters beginning in November, February and May, and on
     * 19 February it would report the quarter as starting 1 February. Its accounts call that quarter
     * January–March, like everybody else's. Counting back from the fixed year end is what gets 1 January.
     */
    public function test_a_short_first_year_still_has_the_standard_quarters(): void
    {
        FiscalYear::query()->delete();
        FiscalYear::create([
            'name' => '2026-2027 (short)',
            'start_date' => '2026-11-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->assertSame(
            ['from' => '2027-01-01', 'to' => self::TODAY],
            DashboardPeriod::range(DashboardPeriod::THIS_QUARTER),
        );
    }

    /**
     * But it cannot report from before it began.
     *
     * On 15 November that company's quarter began on 1 October and it has no October, so the span starts
     * where the company's year does. A quarter reaching back before the first transaction would report a
     * month of nothing as a month of nothing sold.
     */
    public function test_a_short_first_year_does_not_reach_before_it_began(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');

        FiscalYear::query()->delete();
        FiscalYear::create([
            'name' => '2026-2027 (short)',
            'start_date' => '2026-11-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->assertSame(
            ['from' => '2026-11-01', 'to' => '2026-11-15'],
            DashboardPeriod::range(DashboardPeriod::THIS_QUARTER),
        );
    }

    /**
     * With no fiscal year at all it falls back to the calendar quarter.
     *
     * A real state for a company mid-setup. Guessing a July start for one that runs on calendar years would
     * be worse than answering with the convention.
     */
    public function test_without_a_fiscal_year_the_quarter_is_the_calendar_one(): void
    {
        FiscalYear::query()->delete();

        $this->assertSame(
            ['from' => '2027-01-01', 'to' => self::TODAY],
            DashboardPeriod::range(DashboardPeriod::THIS_QUARTER),
        );
    }

    /** Year to date is the financial year — 1 July, not 1 January. */
    public function test_year_to_date_is_the_financial_year(): void
    {
        $this->assertSame(
            ['from' => '2026-07-01', 'to' => self::TODAY],
            DashboardPeriod::range(DashboardPeriod::YEAR_TO_DATE),
        );
    }

    /** Every named period ends today, which is the property the whole class is built around. */
    public function test_every_named_period_ends_today(): void
    {
        foreach ([
            DashboardPeriod::THIS_MONTH,
            DashboardPeriod::THIS_QUARTER,
            DashboardPeriod::YEAR_TO_DATE,
        ] as $period) {
            $this->assertSame(self::TODAY, DashboardPeriod::range($period)['to'], "[{$period}] does not end today");
        }
    }

    // ─────────────────────────────── a custom range ──

    /** A custom range is taken as given — somebody naming both ends means both ends. */
    public function test_a_custom_range_is_taken_as_given(): void
    {
        $this->assertSame(
            ['from' => '2026-09-01', 'to' => '2026-11-30'],
            DashboardPeriod::range(DashboardPeriod::CUSTOM, '2026-09-01', '2026-11-30'),
        );
    }

    /**
     * Reversed ends are put the right way round rather than producing nothing.
     *
     * A widget handed `from` after `to` reports nought without erroring, which reads as a quiet quarter.
     */
    public function test_a_reversed_custom_range_is_ordered(): void
    {
        $this->assertSame(
            ['from' => '2026-09-01', 'to' => '2026-11-30'],
            DashboardPeriod::range(DashboardPeriod::CUSTOM, '2026-11-30', '2026-09-01'),
        );
    }

    /**
     * A half-specified custom range falls back to this month rather than to half a range.
     *
     * A `from` with no `to` would otherwise read to the end of time, and a `to` with no `from` from the
     * beginning of it.
     */
    public function test_a_half_specified_custom_range_falls_back(): void
    {
        $thisMonth = ['from' => '2027-02-01', 'to' => self::TODAY];

        $this->assertSame($thisMonth, DashboardPeriod::range(DashboardPeriod::CUSTOM, '2026-09-01', null));
        $this->assertSame($thisMonth, DashboardPeriod::range(DashboardPeriod::CUSTOM, null, '2026-11-30'));
        $this->assertSame($thisMonth, DashboardPeriod::range(DashboardPeriod::CUSTOM));
    }

    // ─────────────────────────────── the label ──

    /** A named period is labelled by name. */
    public function test_a_named_period_is_labelled_by_name(): void
    {
        $this->assertSame('This quarter', DashboardPeriod::label(DashboardPeriod::THIS_QUARTER));
        $this->assertSame('Financial year to date', DashboardPeriod::label(DashboardPeriod::YEAR_TO_DATE));
    }

    /**
     * A custom range names its own dates.
     *
     * "Custom range" written over a set of figures says nothing about which figures.
     */
    public function test_a_custom_range_is_labelled_with_its_dates(): void
    {
        $this->assertSame(
            '1 Sep 2026 to 30 Nov 2026',
            DashboardPeriod::label(DashboardPeriod::CUSTOM, '2026-09-01', '2026-11-30'),
        );
    }

    /** A half-specified one is labelled as what it actually shows. */
    public function test_a_half_specified_custom_range_is_labelled_as_this_month(): void
    {
        $this->assertSame('This month', DashboardPeriod::label(DashboardPeriod::CUSTOM, '2026-09-01', null));
    }

    // ─────────────────────────────── on the page ──

    /** The dashboard renders, and defaults to this month. */
    public function test_the_dashboard_defaults_to_this_month(): void
    {
        $page = Livewire::test(Dashboard::class)->assertSuccessful()->instance();

        $this->assertSame(DashboardPeriod::THIS_MONTH, $page->periodKey());
        $this->assertSame(['from' => '2027-02-01', 'to' => self::TODAY], $page->range());
    }

    /**
     * A period in the URL is what the page opens on.
     *
     * "A dashboard someone links to opens on the period they meant" is the item's own wording, and it is the
     * reason these are `#[Url]` properties rather than a Filament filter form.
     */
    public function test_a_period_in_the_url_is_honoured(): void
    {
        $page = Livewire::test(Dashboard::class, ['period' => DashboardPeriod::YEAR_TO_DATE])
            ->assertSuccessful()
            ->instance();

        $this->assertSame(['from' => '2026-07-01', 'to' => self::TODAY], $page->range());
    }

    /** And a nonsense one does not take the page down. */
    public function test_a_nonsense_period_in_the_url_falls_back(): void
    {
        $page = Livewire::test(Dashboard::class, ['period' => 'last_fortnight'])
            ->assertSuccessful()
            ->instance();

        $this->assertSame(DashboardPeriod::THIS_MONTH, $page->periodKey());
    }

    /**
     * Widgets are handed the resolved dates, not the period name.
     *
     * The item's actual requirement — "widgets read the page's filter; none of them keeps its own idea of
     * 'now'". Handing over the name would have made every widget re-derive the range, and twenty widgets
     * deriving one range is twenty chances to differ.
     */
    public function test_widgets_are_handed_the_resolved_dates(): void
    {
        $data = Livewire::test(Dashboard::class, ['period' => DashboardPeriod::THIS_QUARTER])
            ->assertSuccessful()
            ->instance()
            ->getWidgetData();

        $this->assertSame(DashboardPeriod::THIS_QUARTER, $data['period']);
        $this->assertSame('2027-01-01', $data['periodFrom']);
        $this->assertSame(self::TODAY, $data['periodTo']);
    }

    /** The subheading states the period, so the figures are never unlabelled. */
    public function test_the_subheading_states_the_period(): void
    {
        $page = Livewire::test(Dashboard::class, ['period' => DashboardPeriod::YEAR_TO_DATE])
            ->assertSuccessful()
            ->instance();

        $this->assertSame('Financial year to date', $page->getSubheading());
    }

    /**
     * Choosing a named period clears a custom range that was in force.
     *
     * Otherwise the custom dates stay in the URL — harmless while a named period is showing, and back in
     * force the moment somebody picks custom again, showing a range they had moved on from.
     */
    public function test_choosing_a_named_period_clears_a_custom_range(): void
    {
        Livewire::test(Dashboard::class, [
            'period' => DashboardPeriod::CUSTOM,
            'from' => '2026-09-01',
            'to' => '2026-11-30',
        ])
            ->assertSuccessful()
            ->callAction('periodThisMonth')
            ->assertSet('period', DashboardPeriod::THIS_MONTH)
            ->assertSet('from', null)
            ->assertSet('to', null);
    }

    /** And the custom-range action sets both ends. */
    public function test_the_custom_range_action_sets_both_ends(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->callAction('periodCustom', ['from' => '2026-09-01', 'to' => '2026-11-30'])
            ->assertSet('period', DashboardPeriod::CUSTOM)
            ->assertSet('from', '2026-09-01')
            ->assertSet('to', '2026-11-30');
    }
}
