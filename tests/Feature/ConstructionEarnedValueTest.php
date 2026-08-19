<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Pages\JobCostReport;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages\CreateJobBudget;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages\EditJobBudget;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages\ListJobBudgets;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\RelationManagers\LinesRelationManager;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\ForecastLine;
use App\Modules\ConstructionCosting\Models\ForecastRun;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\ProgressMeasurement;
use App\Modules\ConstructionCosting\Services\BudgetService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\EarnedValue;
use App\Modules\ConstructionCosting\Services\ForecastService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Budget, forecast and earned value — `docs/construction-management-plan.md` §3.5 and §14, Phase 3.
 *
 * Phase 3's stated exit condition is "the report that sells the module, and a schedule variance that says
 * *unavailable* rather than zero on an unphased budget". Both are asserted here, the second in four places
 * because it is the failure the whole section is written around: **a zero that means "no data" reads as
 * *exactly on programme*, which is the most reassuring wrong answer this module could give.**
 *
 * The other three properties this file defends, each of which produces a report full of plausible numbers that
 * mean nothing if it breaks:
 *
 *  - **Earned value is measured physically and never derived from cost.** Derived from cost it equals actual
 *    cost, CPI is exactly 1.00, and every job in the system reads precisely on budget forever.
 *  - **Earned value is frozen when measured.** The budget moves when a revision is approved; last month's earned
 *    value must not move with it, or a run of monthly figures is incomparable — which is the whole point of
 *    having them.
 *  - **The baseline does not move once work has been measured against it.** A revision makes a new *current*
 *    version and leaves the baseline alone, so the cost report tracks the revised budget while earned value keeps
 *    comparing against what the job was sold on.
 */
class ConstructionEarnedValueTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labour;

    private CostCode $material;

    private BudgetService $budgets;

    private EarnedValue $evm;

    private ForecastService $forecasts;

    private CostLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'evm@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->labour = CostCode::create(['code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't']);
        $this->material = CostCode::create(['code' => '03.100', 'name' => 'Ready-mix concrete', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 'm3']);

        $this->budgets = app(BudgetService::class);
        $this->evm = app(EarnedValue::class);
        $this->forecasts = app(ForecastService::class);
        $this->ledger = app(CostLedger::class);
    }

    /**
     * A budget of 1,000,000 labour and 500,000 material, approved and baselined.
     *
     * `$phased` decides whether the lines carry a month, which is the difference between a schedule variance and
     * the sentence saying there cannot be one.
     */
    private function approvedBudget(bool $phased = false, string $name = 'Contract award'): JobBudget
    {
        $version = $this->budgets->createVersion($this->job, $name, JobBudget::KIND_ORIGINAL);

        $this->budgets->addLine($version, [
            'cost_code_id' => $this->labour->getKey(),
            'quantity' => 50, 'unit_of_measure' => 't', 'unit_rate' => 20_000, 'amount' => 1_000_000,
            'period_start' => $phased ? '2026-08-01' : null,
        ]);

        $this->budgets->addLine($version, [
            'cost_code_id' => $this->material->getKey(),
            'quantity' => 250, 'unit_of_measure' => 'm3', 'unit_rate' => 2_000, 'amount' => 500_000,
            'period_start' => $phased ? '2026-09-01' : null,
        ]);

        $this->budgets->approve($version);
        $this->budgets->setBaseline($version);

        return $version->refresh();
    }

    private function recordCost(CostCode $code, float $amount, array $attributes = []): CostEntry
    {
        return $this->ledger->record($this->job, $code, array_merge([
            'amount' => $amount,
            'incurred_on' => '2026-08-10',
            'description' => 'Test cost',
        ], $attributes));
    }

    // ------------------------------------------------------------------ versions

    public function test_a_version_is_numbered_and_opens_as_a_draft(): void
    {
        $version = $this->budgets->createVersion($this->job, 'Tender', JobBudget::KIND_ESTIMATE);

        $this->assertSame(1, $version->version_no);
        $this->assertSame(JobBudget::STATUS_DRAFT, $version->status);
        $this->assertFalse($version->is_current);
        $this->assertFalse($version->is_baseline);
    }

    public function test_approving_makes_a_version_current_and_supersedes_the_last_one(): void
    {
        $first = $this->approvedBudget();
        $second = $this->budgets->createVersion($this->job, 'Rev 1');
        $this->budgets->approve($second);

        $this->assertSame(JobBudget::STATUS_SUPERSEDED, $first->refresh()->status);
        $this->assertFalse($first->is_current);
        $this->assertTrue($second->refresh()->is_current);
        $this->assertSame($second->getKey(), JobBudget::currentFor($this->job)->getKey());
    }

    /** An empty budget approved as current reads as a job with no budget at all. */
    public function test_an_empty_version_cannot_be_approved(): void
    {
        $version = $this->budgets->createVersion($this->job, 'Tender');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no lines');

        $this->budgets->approve($version);
    }

    /**
     * A revision starts as a copy.
     *
     * §3.5's reason: a revision after variation twelve differs from the budget before it by a handful of lines,
     * and retyping four hundred is how a revision comes to disagree with the budget it was supposed to revise.
     */
    public function test_a_revision_copies_the_current_versions_lines(): void
    {
        $this->approvedBudget();

        $revision = $this->budgets->createVersion($this->job, 'Rev 1 post VO-3');

        $this->assertSame(2, $revision->lines()->count());
        $this->assertEqualsWithDelta(1_500_000, $revision->budgetAtCompletion(), 0.01);
        // The snapshot travels with the copy, so a recoded library cannot restate the revision either.
        $this->assertSame(
            [CostCode::TYPE_LABOUR, CostCode::TYPE_MATERIAL],
            $revision->lines()->orderBy('id')->pluck('cost_type')->all(),
        );
    }

    public function test_only_one_version_per_job_is_current_or_baseline(): void
    {
        $first = $this->approvedBudget();

        $second = $this->budgets->createVersion($this->job, 'Rev 1');
        $this->budgets->approve($second);
        $this->budgets->setBaseline($second, force: true);

        $this->assertSame(1, JobBudget::query()->where('job_id', $this->job->getKey())->where('is_current', true)->count());
        $this->assertSame(1, JobBudget::query()->where('job_id', $this->job->getKey())->where('is_baseline', true)->count());
        $this->assertFalse($first->refresh()->is_baseline);
    }

    public function test_an_approved_version_refuses_a_new_line(): void
    {
        $version = $this->approvedBudget();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Create a revision');

        $this->budgets->addLine($version, ['cost_code_id' => $this->labour->getKey(), 'amount' => 1]);
    }

    /**
     * A heading takes no budget, for the same reason it takes no cost.
     *
     * The budget column of the four-column report rolls children into their parent, so a figure on both counts
     * the money twice — and the report would look entirely normal while doing it.
     */
    public function test_a_heading_code_refuses_a_budget_line(): void
    {
        $heading = CostCode::create(['code' => '02', 'name' => 'Concrete works', 'cost_type' => CostCode::TYPE_LABOUR]);
        $this->labour->update(['parent_id' => $heading->getKey()]);
        $version = $this->budgets->createVersion($this->job, 'Tender');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a heading');

        $this->budgets->addLine($version, ['cost_code_id' => $heading->getKey(), 'amount' => 100]);
    }

    public function test_the_cost_type_on_a_budget_line_is_snapshotted(): void
    {
        $version = $this->budgets->createVersion($this->job, 'Tender');
        $line = $this->budgets->addLine($version, ['cost_code_id' => $this->labour->getKey(), 'amount' => 100]);

        $this->labour->update(['cost_type' => CostCode::TYPE_SUBCONTRACT]);

        $this->assertSame(CostCode::TYPE_LABOUR, $line->refresh()->cost_type);
    }

    // ------------------------------------------------------------------ baseline

    /**
     * The rule the whole versioning design exists for.
     *
     * Once a measurement exists, moving the baseline restates every earned-value figure ever taken on the job,
     * and each of those was frozen precisely so that could not happen.
     */
    public function test_the_baseline_will_not_move_once_progress_has_been_measured(): void
    {
        $this->approvedBudget();
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);

        $revision = $this->budgets->createVersion($this->job, 'Rev 1');
        $this->budgets->approve($revision);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has progress measured against baseline');

        $this->budgets->setBaseline($revision);
    }

    /** And the two flags then genuinely diverge, which is what makes the refusal above survivable. */
    public function test_the_current_budget_moves_while_the_baseline_stays(): void
    {
        $original = $this->approvedBudget();
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);

        $revision = $this->budgets->createVersion($this->job, 'Rev 1 post VO-3');
        $this->budgets->addLine($revision, ['cost_code_id' => $this->labour->getKey(), 'amount' => 200_000]);
        $this->budgets->approve($revision);

        $this->assertSame($revision->getKey(), JobBudget::currentFor($this->job)->getKey());
        $this->assertSame($original->getKey(), JobBudget::baselineFor($this->job)->getKey());
    }

    // ------------------------------------------------------------------ measurement

    public function test_earned_value_is_the_baseline_budget_times_physical_progress_and_is_frozen(): void
    {
        $this->approvedBudget();

        $measurement = $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);

        $this->assertSame('300000.00', $measurement->earned_value);
        $this->assertSame('1000000.00', $measurement->budget_at_completion);
        $this->assertSame(JobBudget::baselineFor($this->job)->getKey(), $measurement->measured_against_version_id);
    }

    /**
     * The silent failure §14 is written around.
     *
     * If earned value came from cost it would equal actual cost and CPI would be exactly 1.00 — so cost is
     * recorded *after* the measurement here and the frozen figure must not budge.
     */
    public function test_earned_value_is_not_derived_from_cost(): void
    {
        $this->approvedBudget();
        $measurement = $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);

        $this->recordCost($this->labour, 450_000);

        $this->assertSame('300000.00', $measurement->refresh()->earned_value);

        $metrics = $this->evm->metricsFor($this->job, '2026-08-01');

        $this->assertSame(300_000.0, $metrics['earned_value']);
        $this->assertSame(450_000.0, $metrics['actual_cost']);
        $this->assertNotSame(1.0, $metrics['cost_performance_index'], 'CPI of exactly 1.00 means EV came from cost');
        $this->assertSame(0.6667, $metrics['cost_performance_index']);
        $this->assertSame(-150_000.0, $metrics['cost_variance']);
    }

    /** An approved revision must not restate a figure taken before it. */
    public function test_an_approved_revision_does_not_restate_a_frozen_earned_value(): void
    {
        $this->approvedBudget();
        $measurement = $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);

        $revision = $this->budgets->createVersion($this->job, 'Rev 1');
        $revision->lines()->where('cost_code_id', $this->labour->getKey())->update(['amount' => 4_000_000]);
        $this->budgets->approve($revision);

        $this->assertSame('300000.00', $measurement->refresh()->earned_value);
        $this->assertSame(300_000.0, $this->evm->metricsFor($this->job, '2026-08-01')['earned_value']);
    }

    public function test_measuring_without_a_baseline_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no baseline budget');

        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
    }

    public function test_measuring_a_code_with_no_baseline_budget_is_refused(): void
    {
        $this->approvedBudget();
        $other = CostCode::create(['code' => '05.100', 'name' => 'Formwork', 'cost_type' => CostCode::TYPE_LABOUR]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no budget on the baseline');

        $this->evm->measure($this->job, $other, '2026-08-01', ['percent_complete' => 30]);
    }

    /** Units completed is the one method where the percentage is a consequence rather than a judgement. */
    public function test_a_units_based_measurement_derives_its_own_percentage(): void
    {
        $this->approvedBudget();

        $measurement = $this->evm->measure($this->job, $this->labour, '2026-08-01', [
            'method' => ProgressMeasurement::METHOD_UNITS,
            'quantity_completed' => 20,
            'quantity_total' => 50,
            // Deliberately wrong, and deliberately ignored: two disagreeing figures leave a report unable to say
            // which was meant.
            'percent_complete' => 90,
        ]);

        $this->assertSame('40.0000', $measurement->percent_complete);
        $this->assertSame('400000.00', $measurement->earned_value);
    }

    public function test_a_percentage_over_one_hundred_is_refused(): void
    {
        $this->approvedBudget();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 0 and 100');

        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 120]);
    }

    /**
     * Two measurements of one control account in one period would double the job's earned value.
     *
     * The unique index cannot enforce this on its own: most control accounts have a null `wbs_node_id`, and both
     * MySQL and SQLite treat nulls in a unique index as distinct — so a second row would go straight in and the job
     * would read as ahead of schedule. Re-measuring an open month revises the figure in place instead.
     */
    public function test_re_measuring_an_open_period_revises_the_figure_rather_than_adding_a_second(): void
    {
        $this->approvedBudget();
        $first = $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
        $second = $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 40]);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ProgressMeasurement::query()->count());
        $this->assertSame('400000.00', $second->earned_value);
        $this->assertSame(400_000.0, $this->evm->earnedValue($this->job, '2026-08-01'), 'not 700,000');
    }

    /** A locked measurement is evidence: it fed a certificate and an earned-value report. */
    public function test_a_locked_measurement_will_not_be_re_measured(): void
    {
        $this->approvedBudget();
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30])
            ->update(['locked_at' => now()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already measured and locked');

        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 40]);
    }

    /**
     * A control account measured in March and not since has still earned what it earned.
     *
     * Reading only the latest period would report a job as having un-earned its earlier work.
     */
    public function test_earned_value_to_date_keeps_what_earlier_periods_earned(): void
    {
        $this->approvedBudget();
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
        $this->evm->measure($this->job, $this->material, '2026-09-01', ['percent_complete' => 10]);

        $this->assertSame(300_000.0, $this->evm->earnedValue($this->job, '2026-08-01'));
        // 300,000 of labour plus 50,000 of material, not 50,000.
        $this->assertSame(350_000.0, $this->evm->earnedValue($this->job, '2026-09-01'));
    }

    // ------------------------------------------- the rule Phase 3 ends on

    /**
     * **The exit condition.** An unphased budget produces no schedule variance, and says so.
     *
     * Null rather than zero in every one of the three places, because a zero here reads as *exactly on
     * programme*. The note carries the reason so the absence explains itself on the report.
     */
    public function test_an_unphased_budget_reports_schedule_performance_as_unavailable_rather_than_zero(): void
    {
        $this->approvedBudget(phased: false);
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
        $this->recordCost($this->labour, 250_000);

        $metrics = $this->evm->metricsFor($this->job, '2026-08-01');

        $this->assertNull($metrics['planned_value']);
        $this->assertNull($metrics['schedule_variance'], 'a zero here reads as exactly on programme');
        $this->assertNull($metrics['schedule_performance_index']);
        $this->assertSame('Schedule performance unavailable: the budget is not time-phased.', $metrics['schedule_note']);

        // And the cost side is unaffected: those figures need no phasing and are still there.
        $this->assertSame(300_000.0, $metrics['earned_value']);
        $this->assertSame(50_000.0, $metrics['cost_variance']);
    }

    public function test_a_job_with_no_baseline_says_that_instead(): void
    {
        $metrics = $this->evm->metricsFor($this->job, '2026-08-01');

        $this->assertNull($metrics['schedule_variance']);
        $this->assertSame('Schedule performance unavailable: this job has no baseline budget.', $metrics['schedule_note']);
    }

    /** And where the budget *is* phased, the figures appear and the note goes away. */
    public function test_a_phased_budget_produces_a_real_schedule_variance(): void
    {
        $this->approvedBudget(phased: true);
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);

        $metrics = $this->evm->metricsFor($this->job, '2026-08-01');

        // August's plan is the 1,000,000 labour line; the September material line is not due yet.
        $this->assertSame(1_000_000.0, $metrics['planned_value']);
        $this->assertSame(-700_000.0, $metrics['schedule_variance']);
        $this->assertSame(0.3, $metrics['schedule_performance_index']);
        $this->assertNull($metrics['schedule_note']);
    }

    /** The page shows the sentence, which is where anybody actually meets it. */
    public function test_the_cost_report_page_prints_the_unavailable_sentence(): void
    {
        $this->approvedBudget(phased: false);
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
        $this->recordCost($this->labour, 250_000);

        Livewire::test(JobCostReport::class)
            ->set('data.job_id', $this->job->getKey())
            ->set('data.period_start', '2026-08-01')
            ->assertSee('Schedule performance unavailable: the budget is not time-phased.')
            ->assertDontSee('Choose a job.');
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_budget_register_renders_with_its_versions(): void
    {
        $this->approvedBudget();

        Livewire::test(ListJobBudgets::class)
            ->assertSuccessful()
            ->assertSee('Contract award')
            ->assertSee('1,500,000.00');
    }

    /**
     * Created through the service, so a version made on the screen is numbered and copied like any other.
     *
     * The same reason `CreateCostEntry` routes through `CostLedger`: a `CreateRecord` writing the row itself would
     * be a second, weaker path into the same rules.
     */
    public function test_creating_a_version_on_the_screen_copies_the_current_budget(): void
    {
        $this->approvedBudget();

        Livewire::test(CreateJobBudget::class)
            ->fillForm([
                'job_id' => $this->job->getKey(),
                'name' => 'Rev 1 post VO-3',
                'kind' => JobBudget::KIND_REVISION,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $revision = JobBudget::query()->where('name', 'Rev 1 post VO-3')->firstOrFail();

        $this->assertSame(2, $revision->version_no);
        $this->assertSame(2, $revision->lines()->count());
    }

    public function test_the_approve_action_makes_a_version_current(): void
    {
        $version = $this->budgets->createVersion($this->job, 'Tender');
        $this->budgets->addLine($version, ['cost_code_id' => $this->labour->getKey(), 'amount' => 1_000]);

        Livewire::test(ListJobBudgets::class)
            ->callTableAction('approve', $version);

        $this->assertTrue($version->refresh()->is_current);
    }

    /** The lines tab offers no create button on an approved version, because the service would refuse it. */
    public function test_the_lines_tab_closes_once_a_version_is_approved(): void
    {
        $draft = $this->budgets->createVersion($this->job, 'Tender');
        $this->budgets->addLine($draft, ['cost_code_id' => $this->labour->getKey(), 'amount' => 1_000]);

        $this->linesTab($draft)->assertActionVisible(TestAction::make('create')->table());

        $this->budgets->approve($draft);

        $this->linesTab($draft->refresh())->assertActionHidden(TestAction::make('create')->table());
    }

    private function linesTab(JobBudget $version): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(LinesRelationManager::class, [
            'ownerRecord' => $version,
            'pageClass' => EditJobBudget::class,
        ]);
    }

    // ------------------------------------------------------------------ estimates

    public function test_the_three_estimate_at_completion_methods_are_offered_together(): void
    {
        $this->approvedBudget();
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
        $this->recordCost($this->labour, 450_000);

        $estimates = $this->evm->estimatesAtCompletion($this->job, '2026-08-01', manualCostToComplete: 900_000);

        $this->assertSame(1_350_000.0, $estimates['manual_etc']);
        // 450,000 spent plus the 1,200,000 of the 1,500,000 baseline not yet earned.
        $this->assertSame(1_650_000.0, $estimates['remaining_budget']);
        // 1,500,000 / 0.6667.
        $this->assertEqualsWithDelta(2_250_000, $estimates['cpi_based'], 500);
    }

    /** A job with no cost has no trend to project, and says so rather than dividing by zero. */
    public function test_the_cpi_based_estimate_is_null_without_a_cost_performance_index(): void
    {
        $this->approvedBudget();

        $this->assertNull($this->evm->estimatesAtCompletion($this->job, '2026-08-01')['cpi_based']);
    }

    // ------------------------------------------------------------------ forecasts

    public function test_a_forecast_run_takes_a_line_per_control_account_with_budget_or_cost(): void
    {
        $this->approvedBudget();
        $this->recordCost($this->labour, 450_000);
        $unbudgeted = CostCode::create(['code' => '09.100', 'name' => 'Temporary works', 'cost_type' => CostCode::TYPE_OTHER]);
        $this->recordCost($unbudgeted, 60_000);

        $run = $this->forecasts->prepare($this->job, '2026-08-01', ForecastLine::EAC_REMAINING_BUDGET);

        // Two budgeted codes plus the one with spend and no budget — the overspend nobody planned.
        $this->assertSame(3, $run->lines()->count());

        $labourLine = $run->lines()->where('cost_code_id', $this->labour->getKey())->firstOrFail();
        $this->assertSame('550000.00', $labourLine->cost_to_complete, '1,000,000 budget less 450,000 spent');
        $this->assertSame('1000000.00', $labourLine->forecast_final_cost);

        $overspend = $run->lines()->where('cost_code_id', $unbudgeted->getKey())->firstOrFail();
        $this->assertSame('0.00', $overspend->cost_to_complete);
        $this->assertSame('60000.00', $overspend->forecast_final_cost);
    }

    public function test_a_second_forecast_for_the_same_month_is_refused(): void
    {
        $this->approvedBudget();
        $this->forecasts->prepare($this->job, '2026-08-01');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has a forecast');

        $this->forecasts->prepare($this->job, '2026-08-01');
    }

    public function test_issuing_fixes_a_run_and_cannot_be_repeated(): void
    {
        $this->approvedBudget();
        $run = $this->forecasts->prepare($this->job, '2026-08-01');

        $this->assertSame(ForecastRun::STATUS_ISSUED, $this->forecasts->issue($run)->status);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already issued');

        $this->forecasts->issue($run->refresh());
    }

    /**
     * A line records the method that produced it, not the one that was asked for.
     *
     * §14's reason: "the forecast went up" and "somebody changed the method" are different facts, and only one of
     * them is news. A CPI-based header over a line with no CPI would make the second look like the first.
     */
    public function test_a_line_with_no_cost_performance_index_falls_back_and_says_so(): void
    {
        $this->approvedBudget();
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
        $this->recordCost($this->labour, 450_000);

        $run = $this->forecasts->prepare($this->job, '2026-08-01', ForecastLine::EAC_CPI);

        $labour = $run->lines()->where('cost_code_id', $this->labour->getKey())->firstOrFail();
        $material = $run->lines()->where('cost_code_id', $this->material->getKey())->firstOrFail();

        $this->assertSame(ForecastLine::EAC_CPI, $labour->eac_method);
        // 1,000,000 / (300,000 / 450,000) = 1,500,000, less the 450,000 already spent.
        $this->assertEqualsWithDelta(1_050_000, (float) $labour->cost_to_complete, 1);

        $this->assertSame(ForecastLine::EAC_REMAINING_BUDGET, $material->eac_method, 'no measurement, so no trend');
        $this->assertStringContainsString('No cost performance index', $material->notes);
    }

    public function test_two_runs_can_be_compared_and_a_changed_method_is_flagged(): void
    {
        $this->approvedBudget();
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);
        $this->recordCost($this->labour, 450_000);

        $august = $this->forecasts->prepare($this->job, '2026-08-01', ForecastLine::EAC_REMAINING_BUDGET);
        $september = $this->forecasts->prepare($this->job, '2026-09-01', ForecastLine::EAC_CPI);

        $movement = collect($this->forecasts->movement($august, $september))
            ->keyBy('cost_code_id');

        $labour = $movement[$this->labour->getKey()];

        $this->assertSame(1_000_000.0, $labour['was']);
        $this->assertEqualsWithDelta(1_500_000, $labour['now'], 1);
        $this->assertEqualsWithDelta(500_000, $labour['movement'], 1);
        $this->assertTrue($labour['method_changed'], 'the method moved, which is its own explanation');

        // Ordered by the size of the movement: the question is never "what does 03.100 say".
        $this->assertSame($this->labour->getKey(), array_key_first($movement->all()));
    }

    // ------------------------------------------------------- the four-column report

    /**
     * §3.5's table, which is the report Phase 3 is judged on.
     *
     * Budget from the current version, actual and accrued separated, cost to complete from the latest forecast,
     * forecast final as the sum of the three, variance as budget less forecast final.
     */
    public function test_the_four_column_report_reads_budget_actual_accrued_and_forecast(): void
    {
        $this->approvedBudget();
        $this->recordCost($this->labour, 450_000);
        $this->recordCost($this->labour, 30_000, ['kind' => CostEntry::KIND_ACCRUAL]);
        $this->forecasts->prepare($this->job, '2026-08-01', ForecastLine::EAC_REMAINING_BUDGET);

        $rows = collect($this->ledger->fourColumnReport($this->job))->keyBy('code');
        $labour = $rows['02.100'];

        $this->assertSame(1_000_000.0, $labour['budget']);
        $this->assertSame(450_000.0, $labour['actual'], 'the accrual is not actual cost');
        $this->assertSame(30_000.0, $labour['accrued']);
        $this->assertSame(520_000.0, $labour['cost_to_complete'], '1,000,000 less the 480,000 spent and accrued');
        $this->assertSame(1_000_000.0, $labour['forecast_final']);
        $this->assertSame(0.0, $labour['variance']);
    }

    /**
     * **Committed is a real figure since Phase 5, and zero now means "nothing on order".**
     *
     * It was `null` until then, and this test asserted that: zero would have read as "nothing is on order", which
     * was a statement of fact this module could not make before procurement existed. Somebody deciding whether a
     * code had room left in it would have been reading a figure wrong by construction.
     *
     * That distinction is what let §5 fill the column in without restating anything, and it is why this assertion
     * changed rather than the meaning of the column. What is asserted now is the other half of the same care: with
     * no orders raised, the figure is 0.00 and it means what it says. `ConstructionCommitmentTest` covers it
     * carrying an actual order.
     */
    public function test_the_committed_column_is_zero_when_nothing_is_on_order(): void
    {
        $this->approvedBudget();

        $this->assertSame(
            0.0,
            collect($this->ledger->fourColumnReport($this->job))->firstWhere('code', '02.100')['committed'],
        );
    }

    /** And the forecast columns are null until there is a forecast — not the spend to date dressed as one. */
    public function test_the_forecast_columns_are_null_until_a_forecast_exists(): void
    {
        $this->approvedBudget();
        $this->recordCost($this->labour, 450_000);

        $labour = collect($this->ledger->fourColumnReport($this->job))->firstWhere('code', '02.100');

        $this->assertNull($labour['cost_to_complete']);
        $this->assertNull($labour['forecast_final'], 'a job with no forecast is not a job finishing on its current spend');
        $this->assertNull($labour['variance']);
        $this->assertSame(450_000.0, $labour['actual']);
    }

    /** A negative variance is the overspend, and the report has to show it. */
    public function test_a_forecast_over_budget_reports_a_negative_variance(): void
    {
        $this->approvedBudget();
        $this->recordCost($this->labour, 1_200_000);
        $this->forecasts->prepare($this->job, '2026-08-01', ForecastLine::EAC_MANUAL, [
            $this->labour->getKey() => 100_000,
        ]);

        $labour = collect($this->ledger->fourColumnReport($this->job))->firstWhere('code', '02.100');

        $this->assertSame(1_300_000.0, $labour['forecast_final']);
        $this->assertSame(-300_000.0, $labour['variance']);
    }

    /**
     * Rolls up the job tree, so a development shows its towers (§1.2) — **budget as well as cost**.
     *
     * The budget is held on the job that was tendered, and reporting happens at whatever level somebody asks. If
     * cost rolled up and budget did not, a development that is exactly on budget would report a variance equal to
     * its entire spend and read as a disaster.
     */
    public function test_the_report_rolls_up_a_child_jobs_budget_and_cost(): void
    {
        $development = Job::create(['code' => 'D-1', 'name' => 'Development']);
        $this->job->update(['parent_id' => $development->getKey()]);

        $this->approvedBudget();
        $this->recordCost($this->labour, 450_000);
        $this->forecasts->prepare($this->job, '2026-08-01', ForecastLine::EAC_REMAINING_BUDGET);

        $labour = collect($this->ledger->fourColumnReport($development->refresh()))->firstWhere('code', '02.100');

        $this->assertSame(1_000_000.0, $labour['budget']);
        $this->assertSame(450_000.0, $labour['actual']);
        $this->assertSame(550_000.0, $labour['cost_to_complete']);
        $this->assertSame(0.0, $labour['variance']);
    }

    /** And earned value rolls up with it, rather than reporting progress against no budget at all. */
    public function test_earned_value_rolls_up_to_a_parent_job(): void
    {
        $development = Job::create(['code' => 'D-1', 'name' => 'Development']);
        $this->job->update(['parent_id' => $development->getKey()]);

        $this->approvedBudget(phased: true);
        $this->evm->measure($this->job, $this->labour, '2026-08-01', ['percent_complete' => 30]);

        $metrics = $this->evm->metricsFor($development->refresh(), '2026-08-01');

        $this->assertSame(1_500_000.0, $metrics['budget_at_completion'], 'the tower\'s baseline, read at the development');
        $this->assertSame(300_000.0, $metrics['earned_value']);
        $this->assertSame(-700_000.0, $metrics['schedule_variance']);
        $this->assertNull($metrics['schedule_note']);
    }

    /**
     * §18.3's query budget, and the reason it is written **with rows**.
     *
     * "A `getStateUsing()` that sums entries per row is five hundred queries on a five-hundred-row report", and
     * the resources smoke test would never catch it because it creates only a user, so every table it renders is
     * empty. This is the deferred Phase 0 item: the report was the thing there was nothing to budget until now.
     *
     * The assertion is that the count does not move with the number of rows, rather than a magic number — the
     * shape of the failure is per-row growth, and a fixed count would have to be edited every time a legitimate
     * query is added.
     */
    public function test_the_report_does_not_grow_a_query_per_row(): void
    {
        $this->approvedBudget(phased: true);

        $count = function (int $codes, string $prefix): int {
            $version = $this->budgets->createVersion($this->job, "Rev with {$codes} codes");

            for ($i = 0; $i < $codes; $i++) {
                $code = CostCode::create([
                    'code' => $prefix.'.'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'name' => "Code {$i}",
                    'cost_type' => CostCode::TYPE_MATERIAL,
                ]);

                $this->budgets->addLine($version, [
                    'cost_code_id' => $code->getKey(), 'amount' => 1_000, 'period_start' => '2026-08-01',
                ]);
                $this->recordCost($code, 400);
            }

            $this->budgets->approve($version);

            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            $this->ledger->fourColumnReport($this->job);
            $this->evm->metricsFor($this->job, '2026-08-01');

            return $queries;
        };

        $small = $count(2, '20');
        $large = $count(30, '21');

        $this->assertSame($small, $large, "the report costs {$small} queries at 2 codes and {$large} at 30");
    }

    /** The latest forecast, not the sum of them — a snapshot summed with its successor forecasts the job twice. */
    public function test_the_report_reads_the_latest_forecast_rather_than_all_of_them(): void
    {
        $this->approvedBudget();
        $this->recordCost($this->labour, 450_000);

        $this->forecasts->prepare($this->job, '2026-08-01', ForecastLine::EAC_MANUAL, [$this->labour->getKey() => 100_000]);
        $this->forecasts->prepare($this->job, '2026-09-01', ForecastLine::EAC_MANUAL, [$this->labour->getKey() => 700_000]);

        $rows = collect($this->ledger->fourColumnReport($this->job))->keyBy('code');

        $this->assertSame(700_000.0, $rows['02.100']['cost_to_complete']);
        // And asking as at August gets August's answer, not September's.
        $august = collect($this->ledger->fourColumnReport($this->job, '2026-08-01'))->keyBy('code');
        $this->assertSame(100_000.0, $august['02.100']['cost_to_complete']);
    }
}
