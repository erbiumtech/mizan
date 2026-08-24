<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Filament\Pages\PipelineByStage;
use App\Modules\Crm\Filament\Pages\RottingDeals;
use App\Modules\Crm\Filament\Pages\SalesForecast;
use App\Modules\Crm\Filament\Pages\TargetAttainment;
use App\Modules\Crm\Filament\Pages\WinLoss;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Models\SalesTarget;
use App\Modules\Crm\Services\OpportunityService;
use App\Modules\Employees\Models\Employee;
use App\Support\Reporting\NoReportPane;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The five CRM reports — `docs/reports-expansion-plan.md` Phase 1.2.
 *
 * Phase 1's premise is "no new business logic", and `CrmPipelineTest` already covers the arithmetic:
 * the weighted forecast, the stored rate that must not move, the rotting rules. So this file does not
 * re-assert any of that. What it asserts is everything the reports add on top, which is where a report
 * that surfaces an existing figure can still be wrong:
 *
 *  - **The window each report chooses.** Three of the five turn one date into a period, and each turns it
 *    into a different one. A forecast that looked backwards or a win rate measured from 1 January would
 *    both produce entirely plausible figures.
 *  - **How the figures are stated.** A null win rate is not nought per cent and an unset target is not a
 *    person who missed. Both would read as failure.
 *  - **That the page and the pane agree.** They are two screens off one payload, and if they ever
 *    disagree, both are in doubt — see the plan's own risk list, which says this in the widget context and
 *    means it here too.
 *  - **That the pane draws them without Accounting.** CRM requires nothing (`crms-plan.md` §1), so a
 *    company can have these five reports and no chart of accounts at all.
 */
class CrmReportsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Pipeline $pipeline;

    /** Fixed, because three of the five reports derive their period from it and a test that drifts over a
     *  month boundary is a test that fails on the 1st. Inside the fiscal year created below. */
    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['crm', 'employees', 'invoicing'] as $module) {
            $this->setModule($module, true);
        }

        // 1 July to 30 June, which is the whole point of the win/loss period assertion below. Not seeded
        // by the tenant helper, and ReportPeriod falls back to the calendar year without one — so a test
        // about the fiscal year has to create the fiscal year or it proves the fallback instead.
        FiscalYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->pipeline = $this->makePipeline();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function makePipeline(string $name = 'New business', bool $default = true): Pipeline
    {
        $pipeline = Pipeline::create(['name' => $name, 'is_default' => $default]);

        $pipeline->stages()->createMany([
            ['name' => 'Qualification', 'sort' => 1, 'probability_pct' => 10, 'rot_after_days' => 14],
            ['name' => 'Proposal', 'sort' => 2, 'probability_pct' => 50, 'rot_after_days' => 7],
            ['name' => 'Won', 'sort' => 3, 'probability_pct' => 100, 'is_won' => true],
            ['name' => 'Lost', 'sort' => 4, 'probability_pct' => 0, 'is_lost' => true],
        ]);

        return $pipeline->fresh('stages');
    }

    private function stage(string $name, ?Pipeline $pipeline = null): PipelineStage
    {
        return ($pipeline ?? $this->pipeline)->stages->firstWhere('name', $name);
    }

    private function openDeal(array $attributes = []): Opportunity
    {
        $lead = Lead::create([
            'company_name' => 'Karachi Textiles',
            'person_name' => 'Ayesha Khan',
            'email' => 'ayesha'.Lead::query()->count().'@karachitextiles.test',
        ]);

        return app(OpportunityService::class)->open(new Opportunity(array_merge([
            'title' => 'Warehouse system',
            'pipeline_id' => $this->pipeline->id,
            'pipeline_stage_id' => $this->stage('Qualification')->id,
            'lead_id' => $lead->id,
            'amount' => 500000,
        ], $attributes)));
    }

    private function makeEmployee(string $id = 'EMP-1'): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'name' => $id,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
    }

    /** @return array<string, mixed> */
    private function report(string $key, ?string $asOf = null): array
    {
        $payload = ReportRenderers::render($key, $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, "no renderer is registered for {$key}");

        return $payload;
    }

    /** Every cell of a report, flattened — for asserting that something is or is not stated anywhere. */
    private function cells(array $payload): string
    {
        return collect($payload['rows'])->flatten()->implode(' | ');
    }

    // ──────────────────────────────────────────────────── pipeline by stage ──

    /**
     * A company may have several pipelines and the report reads one, so it says which.
     *
     * The alternative was a `pipeline` filter, which would put a CRM concept in Accounting's `ASKS` — the
     * coupling Phase 1.2 removed from `supports()`. Naming the pipeline is the smaller compromise, but
     * only while it is actually named: a silent choice of pipeline is a report that is wrong for half its
     * readers and admits nothing.
     */
    public function test_pipeline_by_stage_names_the_pipeline_it_read(): void
    {
        $this->openDeal();

        $payload = $this->report('PipelineByStage');

        $this->assertStringContainsString('New business', $payload['subtitle']);
        $this->assertStringContainsString('NEW BUSINESS', $payload['note']);
    }

    /** And reads only that one. A total mixing two pipelines is a figure nobody asked for. */
    public function test_pipeline_by_stage_counts_only_the_default_pipeline(): void
    {
        $this->openDeal(['amount' => 400000]);

        $other = $this->makePipeline('Renewals', default: false);
        $this->openDeal([
            'amount' => 999999,
            'pipeline_id' => $other->id,
            'pipeline_stage_id' => $this->stage('Qualification', $other)->id,
        ]);

        $payload = $this->report('PipelineByStage');

        $this->assertSame(400000.0, $payload['tiles'][0]['value']);
        $this->assertStringNotContainsString('999,999', $this->cells($payload));
    }

    /** The record row foots the rows above it, which is the only reason a reader can trust it. */
    public function test_pipeline_by_stage_foots_to_the_rows_above_it(): void
    {
        $this->openDeal(['amount' => 100000]);
        $second = $this->openDeal(['amount' => 300000]);
        app(OpportunityService::class)->moveTo($second, $this->stage('Proposal'), $this->actor);

        $payload = $this->report('PipelineByStage');

        // Column 1 is the count, 2 the value, 3 the weighted value.
        foreach ([1, 2, 3] as $column) {
            $rows = array_sum(array_map(
                fn (array $row): float => (float) str_replace(',', '', $row[$column]),
                $payload['rows'],
            ));

            $this->assertSame(
                (float) str_replace(',', '', $payload['footer'][$column]),
                $rows,
                "column {$column}'s total does not add up the rows shown above it",
            );
        }

        // 100,000 at 10% plus 300,000 at 50% — stated as well as footed, so a footer that agreed with
        // wrong rows would still fail.
        $this->assertSame(400000.0, $payload['tiles'][0]['value']);
        $this->assertSame(160000.0, $payload['tiles'][1]['value']);
    }

    /**
     * A company that has not set up a pipeline gets a sentence, not an empty grid.
     *
     * This is the branch a brand-new CRM company opens the report in, and "no pipeline has been set up" is
     * a different fact from "nothing is in the pipeline" — the first is something to go and do.
     */
    public function test_pipeline_by_stage_says_when_there_is_no_pipeline_at_all(): void
    {
        Opportunity::query()->delete();
        PipelineStage::query()->delete();
        Pipeline::query()->delete();

        $payload = $this->report('PipelineByStage');

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO PIPELINE', $payload['note']);
        $this->assertStringContainsString('No pipeline has been set up', $payload['empty']);
    }

    // ────────────────────────────────────────────────────────────── forecast ──

    /**
     * The forecast is the month the date falls in, looking forward.
     *
     * A forecast of a period that has closed is a win/loss report, and a forecast of the whole fiscal year
     * to date is neither. This is the assertion behind that choice: a deal expected next month is not in
     * this month's forecast, however certain it is.
     */
    public function test_the_forecast_covers_the_month_of_the_date_and_not_beyond_it(): void
    {
        $this->openDeal(['amount' => 200000, 'expected_close_on' => '2026-08-28']);
        $this->openDeal(['amount' => 700000, 'expected_close_on' => '2026-09-02']);

        $payload = $this->report('SalesForecast');

        $this->assertStringContainsString('2026-08-01', $payload['subtitle']);
        $this->assertStringContainsString('2026-08-31', $payload['subtitle']);
        // 200,000 at 10%, and nothing from September.
        $this->assertSame(20000.0, $payload['tiles'][0]['value']);
        $this->assertSame(200000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('1 deals expected to close', mb_strtolower($payload['note']));
    }

    /** A won deal is an invoice waiting to be raised. Forecasting it states the same money twice. */
    public function test_the_forecast_excludes_a_deal_that_has_already_been_won(): void
    {
        $deal = $this->openDeal(['amount' => 200000, 'expected_close_on' => '2026-08-28']);
        app(OpportunityService::class)->markWon($deal, $this->actor);

        $payload = $this->report('SalesForecast');

        $this->assertSame(0.0, $payload['tiles'][0]['value']);
        $this->assertSame(0.0, $payload['tiles'][1]['value']);
    }

    /**
     * The stage rows are the forecast's own deals, and they add up to it.
     *
     * They used to be the whole open pipeline, sitting under a total for one month — a reader adding the
     * Weighted column would have got a different number from the tile above it, which is the plan's own
     * "plausible number that is wrong". Both halves now read one pipeline and one window.
     */
    public function test_the_forecast_rows_add_up_to_the_forecast(): void
    {
        $this->openDeal(['amount' => 200000, 'expected_close_on' => '2026-08-28']);
        $proposal = $this->openDeal(['amount' => 400000, 'expected_close_on' => '2026-08-29']);
        app(OpportunityService::class)->moveTo($proposal, $this->stage('Proposal'), $this->actor);

        // Outside the month, and in the pipeline being read: the row it would land in is the same one.
        $this->openDeal(['amount' => 900000, 'expected_close_on' => '2026-09-15']);

        // Inside the month, in another pipeline: the total must not reach across, or it disagrees with
        // rows that cannot.
        $other = $this->makePipeline('Renewals', default: false);
        $this->openDeal([
            'amount' => 700000,
            'expected_close_on' => '2026-08-30',
            'pipeline_id' => $other->id,
            'pipeline_stage_id' => $this->stage('Qualification', $other)->id,
        ]);

        $payload = $this->report('SalesForecast');

        foreach ([1, 2, 3] as $column) {
            $rows = array_sum(array_map(
                fn (array $row): float => (float) str_replace(',', '', $row[$column]),
                $payload['rows'],
            ));

            $this->assertSame(
                (float) str_replace(',', '', $payload['footer'][$column]),
                $rows,
                "column {$column} does not add up to the forecast stated above it",
            );
        }

        // 200,000 at 10% plus 400,000 at 50%, and neither September nor the other pipeline.
        $this->assertSame(220000.0, $payload['tiles'][0]['value']);
        $this->assertSame(600000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('New business', $payload['subtitle']);
    }

    /**
     * Mixed currencies are named rather than hidden.
     *
     * The figures are converted at each deal's stored rate, which is right, and it means a single total
     * can be made of three currencies' worth of assumptions. A reader who knows that can judge it; a
     * reader who does not cannot.
     */
    public function test_the_forecast_names_the_currencies_when_more_than_one_is_in_play(): void
    {
        $this->openDeal(['amount' => 200000, 'expected_close_on' => '2026-08-28']);

        $single = $this->report('SalesForecast');
        // No currency segment at all — the separator, not a code, because the company's own currency is
        // the one that must never be announced.
        $this->assertStringNotContainsString(' · ', $single['note']);

        $this->openDeal([
            'amount' => 1000,
            'currency_code' => 'EUR',
            'exchange_rate' => 300,
            'expected_close_on' => '2026-08-29',
        ]);

        $this->assertStringContainsString('EUR', $this->report('SalesForecast')['note']);
    }

    // ────────────────────────────────────────────────────────────── win/loss ──

    /**
     * The financial year, not the calendar year.
     *
     * This application's year runs 1 July to 30 June. Read on 1 February, a win rate from 1 January covers
     * one month and a win rate from 1 July covers seven — and the first would report a team's whole year as
     * whatever happened since Christmas. `ReportPeriod` exists for exactly this, and the deal below is
     * placed where the two answers differ.
     */
    public function test_win_loss_covers_the_financial_year_and_not_the_calendar_year(): void
    {
        $won = $this->openDeal(['amount' => 250000]);
        app(OpportunityService::class)->markWon($won, $this->actor);
        // August 2026: inside the 2026-2027 fiscal year, outside the calendar year of the date read.
        $won->forceFill(['closed_on' => '2026-08-15'])->save();

        $payload = $this->report('WinLoss', '2027-02-01');

        $this->assertStringContainsString('2026-07-01', $payload['subtitle']);
        $this->assertSame(1.0, $payload['tiles'][0]['value']);
        $this->assertStringContainsString('WIN RATE 100.0%', $payload['note']);
    }

    /** A deal closed in the previous fiscal year is last year's result. */
    public function test_win_loss_excludes_a_deal_closed_in_the_previous_financial_year(): void
    {
        $won = $this->openDeal(['amount' => 250000]);
        app(OpportunityService::class)->markWon($won, $this->actor);
        $won->forceFill(['closed_on' => '2026-06-29'])->save();

        $payload = $this->report('WinLoss');

        $this->assertSame(0.0, $payload['tiles'][0]['value']);
        $this->assertSame([], $payload['rows']);
    }

    /**
     * Nothing closed is not a nought per cent win rate.
     *
     * The service returns null for the rate in that case and the report has to keep the distinction. "0%"
     * against a quiet month reads as a team that lost everything it touched.
     */
    public function test_win_loss_says_nothing_closed_rather_than_stating_a_nought_per_cent_rate(): void
    {
        $this->openDeal();

        $payload = $this->report('WinLoss');

        $this->assertStringContainsString('NOTHING HAS CLOSED', mb_strtoupper($payload['note']));
        $this->assertStringNotContainsString('0.0%', $payload['note']);
    }

    /** Lost deals are grouped by the reason recorded against them, which is what makes the report useful. */
    public function test_win_loss_groups_the_losses_by_the_reason_they_were_given(): void
    {
        $reason = LostReason::create(['name' => 'Price']);
        $lost = $this->openDeal();
        app(OpportunityService::class)->markLost($lost, $reason, $this->actor);
        // Closing stamps today, and the report is read to a fixed date — so the date is stated rather
        // than left to be whenever the suite happens to run.
        $lost->forceFill(['closed_on' => '2026-08-15'])->save();

        $payload = $this->report('WinLoss');

        $this->assertStringContainsString('Lost reason · Price', $this->cells($payload));
        $this->assertSame(1.0, $payload['tiles'][1]['value']);
    }

    // ─────────────────────────────────────────────────────────────── rotting ──

    /**
     * Both reasons, and the report says which applies to each row.
     *
     * "Nothing planned" is the more damning of the two — a deal nobody has planned anything for is not
     * slow, it is unowned — and a report that merged the two into "rotting" would hide the difference
     * between a deal to chase and a deal to assign.
     */
    public function test_rotting_states_why_each_deal_is_rotting(): void
    {
        $this->openDeal(['title' => 'Unplanned deal']);

        $payload = $this->report('RottingDeals');

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString('Unplanned deal', $this->cells($payload));
        $this->assertStringContainsString('Nothing planned', $this->cells($payload));
    }

    /** The tile is what is at risk if none of them moves, which is the reason to open the report. */
    public function test_rotting_totals_the_value_at_risk(): void
    {
        $this->openDeal(['amount' => 120000]);
        $this->openDeal(['amount' => 80000]);

        $payload = $this->report('RottingDeals');

        $this->assertSame(200000.0, $payload['tiles'][0]['value']);
        $this->assertStringContainsString('2 DEALS NEED ATTENTION', $payload['note']);
    }

    /** And says so plainly when there is nothing to chase, rather than showing an empty grid. */
    public function test_rotting_says_so_when_nothing_is_rotting(): void
    {
        $payload = $this->report('RottingDeals');

        $this->assertSame([], $payload['rows']);
        $this->assertStringContainsString('Nothing is rotting', $payload['empty']);
    }

    // ──────────────────────────────────────────────────────────── attainment ──

    /**
     * Every target covering the date, whatever length it is.
     *
     * Targets carry their own start and end in this application, so a quarterly target is as legitimate as
     * a monthly one. The date picks which targets *apply* rather than which month to measure — a report
     * that assumed calendar months would silently drop every target not set up that way.
     */
    public function test_attainment_shows_every_target_covering_the_date_whatever_its_length(): void
    {
        $employee = $this->makeEmployee();

        SalesTarget::create([
            'employee_id' => $employee->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'target_amount' => 500000,
            'kind' => SalesTarget::KIND_WON_VALUE,
        ]);
        SalesTarget::create([
            'employee_id' => $employee->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-09-30',
            'target_amount' => 1500000,
            'kind' => SalesTarget::KIND_WON_VALUE,
        ]);
        // Outside the date: the quarter after.
        SalesTarget::create([
            'employee_id' => $employee->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-12-31',
            'target_amount' => 9000000,
            'kind' => SalesTarget::KIND_WON_VALUE,
        ]);

        $payload = $this->report('TargetAttainment');

        $this->assertCount(2, $payload['rows']);
        $this->assertSame(2000000.0, $payload['tiles'][0]['value']);
        $this->assertStringNotContainsString('9,000,000', $this->cells($payload));
        $this->assertStringContainsString('EMP-1', $this->cells($payload));
    }

    /**
     * A target of nought attains nothing — not nought per cent.
     *
     * Dividing by it would be a crash, and the tempting fix is to report 0%. That reads as somebody who
     * missed their target, which is the opposite of what an unset target means.
     */
    public function test_attainment_shows_a_dash_rather_than_nought_per_cent_for_an_unset_target(): void
    {
        SalesTarget::create([
            'employee_id' => $this->makeEmployee()->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'target_amount' => 0,
            'kind' => SalesTarget::KIND_WON_VALUE,
        ]);

        $payload = $this->report('TargetAttainment');

        $this->assertSame('—', $payload['rows'][0][5]);
        $this->assertStringNotContainsString('0.0%', $this->cells($payload));
    }

    /** What the target is measured in, in words. `won_value` is a column value, not a thing to read. */
    public function test_attainment_states_the_kind_of_target_in_words(): void
    {
        SalesTarget::create([
            'employee_id' => $this->makeEmployee()->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'target_amount' => 10,
            'kind' => SalesTarget::KIND_NEW_LEADS,
        ]);

        $payload = $this->report('TargetAttainment');

        $this->assertSame('New Leads', $payload['rows'][0][1]);
        $this->assertStringNotContainsString('new_leads', $this->cells($payload));
    }

    /** Achieved is measured inside the target's own period, not the report's date. */
    public function test_attainment_measures_achievement_inside_the_targets_own_period(): void
    {
        $employee = $this->makeEmployee();

        SalesTarget::create([
            'employee_id' => $employee->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'target_amount' => 500000,
            'kind' => SalesTarget::KIND_WON_VALUE,
        ]);

        $inside = $this->openDeal(['amount' => 250000, 'owner_employee_id' => $employee->id]);
        app(OpportunityService::class)->markWon($inside, $this->actor);
        $inside->forceFill(['closed_on' => '2026-08-10'])->save();

        $outside = $this->openDeal(['amount' => 400000, 'owner_employee_id' => $employee->id]);
        app(OpportunityService::class)->markWon($outside, $this->actor);
        $outside->forceFill(['closed_on' => '2026-07-10'])->save();

        $payload = $this->report('TargetAttainment');

        $this->assertSame(250000.0, $payload['tiles'][1]['value']);
        $this->assertSame('50.0%', $payload['rows'][0][5]);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /**
     * The two screens are one payload.
     *
     * A report has a page and a place in the explorer pane, and this is the assertion that keeps them from
     * being two implementations. The plan's risk list makes this point about widgets — "once a chart and a
     * report disagree, every other number on both screens is in doubt" — and it is exactly as true of a
     * report and its own page.
     */
    public function test_every_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->openDeal(['amount' => 250000, 'expected_close_on' => '2026-08-28']);
        SalesTarget::create([
            'employee_id' => $this->makeEmployee()->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'target_amount' => 500000,
            'kind' => SalesTarget::KIND_WON_VALUE,
        ]);

        foreach ([PipelineByStage::class, SalesForecast::class, WinLoss::class, RottingDeals::class, TargetAttainment::class] as $page) {
            $key = class_basename($page);

            $onThePage = Livewire::test($page, ['asOf' => self::AS_OF])
                ->assertSuccessful()
                ->instance()
                ->statement();

            $inThePane = app(ReportPaneRenderer::class)->for($key, self::AS_OF, false, []);

            $this->assertSame($onThePage, $inThePane, "{$key} draws differently on its page than in the pane");
        }
    }

    /**
     * The key a report is registered under is the key its page is drawn under.
     *
     * Three places agree on it — the catalogue, the renderers, and `Reports::sections()`, which puts the
     * class basename in the URL. A report registered under one name and paged under another renders on its
     * own page and is missing from the hub, which is the confusing half of that failure.
     */
    public function test_each_page_is_registered_under_its_own_class_basename(): void
    {
        $pages = collect(ReportCatalogue::sections()['Sales & pipeline'] ?? [])->keys();

        $this->assertCount(5, $pages, 'the Sales & pipeline section should hold exactly the five CRM reports');

        foreach ($pages as $page) {
            $key = class_basename($page);

            $this->assertTrue(ReportRenderers::has($key), "{$page} is listed in the hub with no renderer");
            $this->assertSame($key, (new $page)->reportKey());
        }
    }

    /**
     * One computation per request, not one per place the answer is displayed.
     *
     * The heading, the subheading and the view each ask the page for its report. Unmemoised that is the
     * whole service run three times for one screen — the exact fault `docs/page-load-performance-plan.md`
     * was written about, and the plan's own risk list names per-row queries in these reports as the thing
     * to watch.
     */
    public function test_a_report_page_computes_its_report_once_per_request(): void
    {
        $runs = 0;

        ReportRenderers::register('WinLoss', function (string $asOf) use (&$runs): array {
            $runs++;

            return app(\App\Modules\Crm\Support\CrmReports::class)->winLoss($asOf);
        });

        $page = new WinLoss;
        $page->asOf = self::AS_OF;

        $page->getHeading();
        $page->getSubheading();
        $page->statement();

        $this->assertSame(1, $runs, 'the report was computed once per place it is displayed');
    }

    /**
     * A page with no renderer fails loudly.
     *
     * There is one way to reach this — the module's provider did not register the report — and the
     * tempting alternative is to render an empty table. That reads as "nothing happened this period",
     * which is a different answer and a much worse one.
     */
    public function test_a_report_page_refuses_to_render_a_blank_report(): void
    {
        ReportRenderers::flush();

        $page = new WinLoss;
        $page->asOf = self::AS_OF;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no renderer');

        $page->statement();
    }

    /**
     * The pane draws these five with no accounting module at all.
     *
     * CRM requires nothing (`docs/crms-plan.md` §1) and must be sellable to a company that has bought
     * neither Invoicing nor Accounting. `NoReportPane` is what such a company gets, and it refused every
     * key — so the five reports were listed in the hub and drawable on their own pages only. What it still
     * refuses is Accounting's own reports, which is correct: there are no accounts to draw.
     */
    public function test_the_pane_draws_a_module_report_for_a_company_with_no_accounting(): void
    {
        $pane = new NoReportPane;

        $this->assertTrue($pane->supportsReport('WinLoss'));
        $this->assertNotNull($pane->for('WinLoss', self::AS_OF, false, []));

        $this->assertFalse($pane->supportsReport('BalanceSheet'));
        $this->assertNull($pane->for('BalanceSheet', self::AS_OF, false, []));

        // And it still offers no filters, drill-throughs or picker options — those are Accounting's.
        $this->assertSame([], $pane->asksFor('WinLoss'));
        $this->assertSame([], $pane->drillable());
    }

    /**
     * The page and the pane share the markup as well as the payload.
     *
     * A page rendering its own copy of the table would be free to show a footer the pane does not, or drop
     * the right-alignment on a column of figures, and the payload assertion above would still pass. The
     * two partials are the only place either screen draws a table.
     */
    public function test_the_page_and_the_pane_render_through_the_same_partials(): void
    {
        $hub = File::get(resource_path('views/filament/pages/reports.blade.php'));
        $page = File::get(resource_path('views/filament/pages/module-report.blade.php'));

        foreach (['report-tiles', 'report-table'] as $partial) {
            $this->assertTrue(File::exists(resource_path("views/filament/partials/{$partial}.blade.php")));

            foreach (['hub' => $hub, 'page' => $page] as $where => $view) {
                $this->assertStringContainsString(
                    "@include('filament.partials.{$partial}'",
                    $view,
                    "the {$where} does not draw its table through {$partial}",
                );
            }
        }

        // The wrapper the table markup used to open with, in the view it was extracted from. Targets the
        // markup rather than the words: the class name appears in the partial's own comment, and an
        // assertion about a word would pass or fail on a comment either way.
        $this->assertStringNotContainsString('<div class="fi-explorer-statement fi-explorer-table">', $hub);
        $this->assertStringNotContainsString('<div class="fi-explorer-tiles">', $hub);
    }

    /**
     * The classes the page's own wrapper uses are styled.
     *
     * Everything inside the two partials is styled already, because the pane has always used it. The page
     * adds a wrapper and a control row of its own, and a class nobody has written a rule for is the failure
     * mode that renders perfectly and looks broken — the same shape as a selector that matches nothing,
     * which is how the context-menu work shipped a feature that was silently inert for a fortnight.
     */
    public function test_the_page_wrapper_classes_are_styled(): void
    {
        $theme = File::get(resource_path('css/filament/admin/theme.css'));
        $view = File::get(resource_path('views/filament/pages/module-report.blade.php'));

        foreach (['fi-explorer-page', 'fi-explorer-page-controls'] as $class) {
            $this->assertStringContainsString($class, $view, "the page view does not use {$class}");
            $this->assertStringContainsString(".{$class} {", $theme, "{$class} has no rule in the theme");
        }
    }

    // ─────────────────────────────────────────────────────────────── gating ──

    /** The five disappear with the module, in the hub and on their own URLs both. */
    public function test_the_reports_are_gated_on_the_crm_module(): void
    {
        Gate::before(fn () => true);

        foreach ($this->pages() as $page) {
            $this->assertTrue($page::canAccess(), class_basename($page).' is unreachable with CRM enabled');
        }

        $this->setModule('crm', false);

        foreach ($this->pages() as $page) {
            $this->assertFalse($page::canAccess(), class_basename($page).' survives CRM being disabled');
        }
    }

    /** And on `ReportView`, which is what every report page in this application gates on. */
    public function test_the_reports_are_gated_on_report_view(): void
    {
        foreach ($this->pages() as $page) {
            $this->assertFalse($page::canAccess(), class_basename($page).' is reachable without ReportView');
        }
    }

    /** @return array<int, class-string> */
    private function pages(): array
    {
        return [PipelineByStage::class, SalesForecast::class, WinLoss::class, RottingDeals::class, TargetAttainment::class];
    }
}
