<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages\CreateLabourRate;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages\ListLabourRates;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages\CreateTrade;
use App\Modules\ConstructionCosting\Filament\Resources\Trades\Pages\ListTrades;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\Pages\ListWorkers;
use App\Modules\ConstructionCosting\Models\LabourRate;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Services\LabourRateService;
use App\Modules\Core\Models\CompanyModule;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Trades, workers and the dated rate table — `docs/construction-management-plan.md` §7.1 and §7.2, Phase 7a.
 *
 * **§7.2's decision is the one this file is mostly about, and it is a decision about what *not* to build.** A
 * `cost_rate_per_hour` column on the worker would restate every historical labour record the moment somebody edited
 * it: "every closed period's cost changes, and there is no journal, no audit and no report of what moved". So the rate
 * is a dated row, and the tests below are the ladder that resolves it.
 *
 * Four properties carry the file:
 *
 *  - **The ladder is `job+trade -> job -> worker/employee -> trade -> company default`**, in that order, and the
 *    third-beats-fourth part is not the interesting bit — *job beats person* is, because it is the one a reader will
 *    assume backwards.
 *  - **A row is a candidate only if nothing it names contradicts the question.** A rate for one job can never be
 *    picked for another, however specific it looks.
 *  - **The three figures resolve independently.** A job row that revises the rate must not silently drop the
 *    company's overtime multiplier to nothing.
 *  - **Nothing invents a rate.** With no row at all, `resolve()` returns null and the caller has to refuse — because
 *    a week of labour costing 0.00 is §18.1's healthy-looking figure hiding an absence.
 */
class ConstructionLabourRateTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $tower;

    private Job $annexe;

    private Trade $steelFixer;

    private Trade $mason;

    private Worker $karim;

    private LabourRateService $rates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'labour@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->tower = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->annexe = Job::create(['code' => 'J-2', 'name' => 'Annexe']);

        $this->steelFixer = Trade::create(['code' => 'STF', 'name' => 'Steel fixer']);
        $this->mason = Trade::create(['code' => 'MAS', 'name' => 'Mason']);

        $this->karim = Worker::create([
            'code' => 'W-001',
            'name' => 'Karim',
            'trade_id' => $this->steelFixer->getKey(),
        ]);

        $this->rates = app(LabourRateService::class);
    }

    // ------------------------------------------------------------------ the registers

    /** A worker defaults to `direct`, because §7.1's whole point is that most hands are not employees. */
    public function test_a_worker_is_direct_labour_unless_somebody_says_otherwise(): void
    {
        $this->assertSame(Worker::ENGAGEMENT_DIRECT, $this->karim->engagement);
        $this->assertNull($this->karim->employee_id);
        $this->assertTrue($this->karim->is_active);
    }

    /** The three engagements sit side by side, which is the distinction the HR register cannot make. */
    public function test_all_three_engagements_live_in_one_register(): void
    {
        Worker::create([
            'code' => 'W-002', 'name' => 'Bilal',
            'engagement' => Worker::ENGAGEMENT_EMPLOYEE, 'employee_id' => 91,
        ]);
        Worker::create([
            'code' => 'W-003', 'name' => 'Yusuf',
            'engagement' => Worker::ENGAGEMENT_SUPPLIED,
        ]);

        $this->assertSame(3, Worker::query()->count());
        $this->assertSame(1, Worker::query()->where('engagement', Worker::ENGAGEMENT_EMPLOYEE)->count());
    }

    /**
     * Engagement is answered from the dates, not from the flag.
     *
     * "Was he on site in March" is asked at exactly the moment somebody disputes a week's hours, and `is_active` only
     * ever answers about today.
     */
    public function test_whether_somebody_was_engaged_reads_the_dates(): void
    {
        $worker = Worker::create([
            'code' => 'W-004', 'name' => 'Asif',
            'started_on' => '2026-03-01', 'ended_on' => '2026-05-31',
        ]);

        $this->assertFalse($worker->wasEngagedOn('2026-02-28'));
        $this->assertTrue($worker->wasEngagedOn('2026-03-01'));
        $this->assertTrue($worker->wasEngagedOn('2026-05-31'));
        $this->assertFalse($worker->wasEngagedOn('2026-06-01'));
    }

    /** A worker who has not left is engaged from their start date onwards, with no end to check. */
    public function test_somebody_still_on_site_has_no_end(): void
    {
        $worker = Worker::create(['code' => 'W-005', 'name' => 'Nadia', 'started_on' => '2026-03-01']);

        $this->assertTrue($worker->wasEngagedOn('2027-01-01'));
    }

    /** A trade carries no rate column at all, which is §7.2's decision seen from the schema. */
    public function test_a_trade_holds_no_rate(): void
    {
        $this->assertFalse(
            Schema::hasColumn('construction_trades', 'cost_rate_per_hour'),
            'A rate column on the trade would restate every historical labour cost the moment somebody edited it.'
        );
        $this->assertFalse(
            Schema::hasColumn('construction_workers', 'cost_rate_per_hour'),
            'Nor on the worker — §7.2 rejects exactly this column.'
        );
    }

    // ------------------------------------------------------------------ the ladder

    /** With nothing but a company default, everybody resolves to it. */
    public function test_the_company_default_answers_when_nothing_else_is_set(): void
    {
        $this->rates->set([], 500, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, $this->steelFixer, $this->karim, on: '2026-08-01');

        $this->assertNotNull($resolved);
        $this->assertSame(500.0, $resolved->costRatePerHour);
        $this->assertSame(LabourRate::TIER_COMPANY_DEFAULT, $resolved->tier);
    }

    /** A trade rate beats the company default. */
    public function test_a_trade_rate_beats_the_company_default(): void
    {
        $this->rates->set([], 500, '2026-01-01');
        $this->rates->set(['trade_id' => $this->steelFixer->getKey()], 650, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, $this->steelFixer, on: '2026-08-01');

        $this->assertSame(650.0, $resolved->costRatePerHour);
        $this->assertSame(LabourRate::TIER_TRADE, $resolved->tier);

        // And the other trade still gets the default, which is what makes it a ladder rather than an override.
        $this->assertSame(500.0, $this->rates->resolve($this->tower, $this->mason, on: '2026-08-01')->costRatePerHour);
    }

    /** A person's own rate beats their trade's. */
    public function test_a_person_rate_beats_the_trade(): void
    {
        $this->rates->set(['trade_id' => $this->steelFixer->getKey()], 650, '2026-01-01');
        $this->rates->set(['worker_id' => $this->karim->getKey()], 800, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, worker: $this->karim, on: '2026-08-01');

        $this->assertSame(800.0, $resolved->costRatePerHour);
        $this->assertSame(LabourRate::TIER_PERSON, $resolved->tier);
    }

    /**
     * **A job rate beats a person's own rate**, and this is the one a reader will assume backwards.
     *
     * §7.2's ladder puts job above worker deliberately: a site allowance applies to everybody working on that site,
     * including the people who carry their own rate elsewhere.
     */
    public function test_a_job_rate_beats_a_person_rate(): void
    {
        $this->rates->set(['worker_id' => $this->karim->getKey()], 800, '2026-01-01');
        $this->rates->set(['job_id' => $this->tower->getKey()], 900, '2026-01-01');

        $onTower = $this->rates->resolve($this->tower, worker: $this->karim, on: '2026-08-01');
        $this->assertSame(900.0, $onTower->costRatePerHour);
        $this->assertSame(LabourRate::TIER_JOB, $onTower->tier);

        // Off that site the same man is back on his own rate, which is what "site allowance" means.
        $this->assertSame(
            800.0,
            $this->rates->resolve($this->annexe, worker: $this->karim, on: '2026-08-01')->costRatePerHour,
        );
    }

    /** Job and trade together is the most specific row there is. */
    public function test_job_and_trade_together_wins_everything(): void
    {
        $this->rates->set([], 500, '2026-01-01');
        $this->rates->set(['trade_id' => $this->steelFixer->getKey()], 650, '2026-01-01');
        $this->rates->set(['worker_id' => $this->karim->getKey()], 800, '2026-01-01');
        $this->rates->set(['job_id' => $this->tower->getKey()], 900, '2026-01-01');
        $this->rates->set([
            'job_id' => $this->tower->getKey(),
            'trade_id' => $this->steelFixer->getKey(),
        ], 1_000, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, $this->steelFixer, $this->karim, on: '2026-08-01');

        $this->assertSame(1_000.0, $resolved->costRatePerHour);
        $this->assertSame(LabourRate::TIER_JOB_AND_TRADE, $resolved->tier);
    }

    /**
     * A rate scoped to one job is **never** a candidate for another, however specific it looks.
     *
     * The filtering half of the ladder, and the half that would fail silently: a job+trade rate leaking onto a
     * neighbouring job would be the most specific row available and would win every time.
     */
    public function test_a_rate_for_one_job_never_answers_for_another(): void
    {
        $this->rates->set([
            'job_id' => $this->tower->getKey(),
            'trade_id' => $this->steelFixer->getKey(),
        ], 1_000, '2026-01-01');

        $this->assertNull($this->rates->resolve($this->annexe, $this->steelFixer, on: '2026-08-01'));
    }

    /** And a rate for one person is never a candidate for somebody else. */
    public function test_a_rate_for_one_person_never_answers_for_another(): void
    {
        $other = Worker::create(['code' => 'W-009', 'name' => 'Imran']);

        $this->rates->set(['worker_id' => $this->karim->getKey()], 800, '2026-01-01');

        $this->assertNull($this->rates->resolve($this->tower, worker: $other, on: '2026-08-01'));
    }

    /**
     * The trade is inferred from the worker when the caller does not pass it.
     *
     * A site sheet names a person and the ladder asks about a trade; making every caller remember
     * `$worker->trade_id` is how a trade rate comes to be ignored for exactly the people it was written for.
     */
    public function test_the_trade_is_inferred_from_the_worker(): void
    {
        $this->rates->set(['trade_id' => $this->steelFixer->getKey()], 650, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, worker: $this->karim, on: '2026-08-01');

        $this->assertSame(650.0, $resolved->costRatePerHour);
    }

    /** And so is the employee, so an employee-scoped rate reaches the worker row that names them. */
    public function test_the_employee_is_inferred_from_the_worker(): void
    {
        $employed = Worker::create([
            'code' => 'W-010', 'name' => 'Bilal',
            'engagement' => Worker::ENGAGEMENT_EMPLOYEE, 'employee_id' => 91,
        ]);

        $this->rates->set(['employee_id' => 91], 1_200, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, worker: $employed, on: '2026-08-01');

        $this->assertSame(1_200.0, $resolved->costRatePerHour);
        $this->assertSame(LabourRate::TIER_PERSON, $resolved->tier);
    }

    // ------------------------------------------------------------------ dates

    /** A rate that has not started yet is not in force. */
    public function test_a_future_rate_does_not_apply_yet(): void
    {
        $this->rates->set([], 500, '2026-09-01');

        $this->assertNull($this->rates->resolve($this->tower, on: '2026-08-01'));
        $this->assertSame(500.0, $this->rates->resolve($this->tower, on: '2026-09-01')->costRatePerHour);
    }

    /** And one that has ended is not either. */
    public function test_a_rate_that_has_ended_does_not_apply(): void
    {
        $this->rates->set([], 500, '2026-01-01', ['effective_to' => '2026-06-30']);

        $this->assertSame(500.0, $this->rates->resolve($this->tower, on: '2026-06-30')->costRatePerHour);
        $this->assertNull($this->rates->resolve($this->tower, on: '2026-07-01'));
    }

    /**
     * **The whole reason the table is dated.** A revision in April leaves March alone.
     *
     * §7.2: "A wage revision effective the first of April must not restate March's job cost."
     */
    public function test_a_revision_leaves_the_earlier_month_alone(): void
    {
        $this->rates->set([], 500, '2026-01-01');
        $this->rates->revise([], 560, '2026-04-01');

        $this->assertSame(500.0, $this->rates->resolve($this->tower, on: '2026-03-31')->costRatePerHour);
        $this->assertSame(560.0, $this->rates->resolve($this->tower, on: '2026-04-01')->costRatePerHour);
    }

    /** Revising closes the row it supersedes the day before, rather than leaving two in force. */
    public function test_revising_closes_the_rate_it_supersedes(): void
    {
        $original = $this->rates->set([], 500, '2026-01-01');
        $this->rates->revise([], 560, '2026-04-01');

        $this->assertSame('2026-03-31', $original->refresh()->effective_to->toDateString());
        $this->assertSame(2, LabourRate::query()->count(), 'the superseded row stays — March was costed at it');
    }

    /** Two rates for the same scope in force at once is refused, because nothing would say which one was used. */
    public function test_two_overlapping_rates_for_one_scope_are_refused(): void
    {
        $this->rates->set([], 500, '2026-01-01');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already in force');

        $this->rates->set([], 560, '2026-04-01');
    }

    /** The refusal is per scope: a trade rate and a company default are supposed to coexist — that is the ladder. */
    public function test_different_scopes_may_overlap_freely(): void
    {
        $this->rates->set([], 500, '2026-01-01');
        $this->rates->set(['trade_id' => $this->steelFixer->getKey()], 650, '2026-01-01');
        $this->rates->set(['job_id' => $this->tower->getKey()], 900, '2026-01-01');

        $this->assertSame(3, LabourRate::query()->count());
    }

    /** A rate that ends before it starts is a typo, and it is refused rather than stored. */
    public function test_a_rate_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot stop applying before it starts');

        $this->rates->set([], 500, '2026-04-01', ['effective_to' => '2026-01-01']);
    }

    /** Zero is refused: it would book a week of labour at nothing and look like a healthy figure. */
    public function test_a_rate_of_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would book a week of labour at nothing');

        $this->rates->set([], 0, '2026-01-01');
    }

    /** An unrecognised scope key is refused, because ignoring it sets a company-wide rate nobody meant. */
    public function test_an_unrecognised_scope_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scoped by');

        $this->rates->set(['worker' => $this->karim->getKey()], 500, '2026-01-01');
    }

    // ------------------------------------------------------------------ the three figures resolve apart

    /**
     * A job row that revises the rate keeps the company's overtime terms.
     *
     * The failure this prevents is quiet and expensive: a single "first matching row wins" would give that job an
     * overtime multiplier of nothing, pricing every overtime hour at plain time.
     */
    public function test_overtime_and_burden_fall_through_independently(): void
    {
        $this->rates->set([], 500, '2026-01-01', ['overtime_multiplier' => 2.0, 'burden_percent' => 22.5]);
        $this->rates->set(['job_id' => $this->tower->getKey()], 900, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, on: '2026-08-01');

        $this->assertSame(900.0, $resolved->costRatePerHour, 'the job row supplies the rate');
        $this->assertSame(2.0, $resolved->overtimeMultiplier, 'and the company row still supplies the multiplier');
        $this->assertSame(22.5, $resolved->burdenPercent);
        $this->assertTrue($resolved->overtimeFromRate);
    }

    /** With nothing stating them, both fall back to config and say that they did. */
    public function test_the_shipped_defaults_are_used_and_reported_as_such(): void
    {
        $this->rates->set([], 500, '2026-01-01');

        $resolved = $this->rates->resolve($this->tower, on: '2026-08-01');

        $this->assertSame((float) config('construction.labour.overtime_multiplier'), $resolved->overtimeMultiplier);
        $this->assertSame(0.0, $resolved->burdenPercent);
        $this->assertFalse($resolved->overtimeFromRate);
        $this->assertFalse($resolved->burdenFromRate);
    }

    /** There is no shipped cost rate, and that asymmetry is deliberate. */
    public function test_nothing_invents_a_cost_rate(): void
    {
        $this->assertNull($this->rates->resolve($this->tower, $this->steelFixer, $this->karim, on: '2026-08-01'));
        $this->assertNull(config('construction.labour.cost_rate_per_hour'));
    }

    // ------------------------------------------------------------------ what an hour costs

    /** Minutes in, money out — minutes because that is what a site sheet records (§7.1). */
    public function test_the_resolved_rate_costs_a_span_of_minutes(): void
    {
        $this->rates->set([], 600, '2026-01-01', ['overtime_multiplier' => 1.5, 'burden_percent' => 20]);

        $resolved = $this->rates->resolve($this->tower, on: '2026-08-01');

        // Eight normal hours and two overtime: 4,800 + (2 × 600 × 1.5) = 6,600.
        $this->assertSame(6_600.0, $resolved->costOf(480, 120));
        $this->assertSame(1_320.0, $resolved->burdenOn(6_600.0));
    }

    /** Half an hour is half an hour, which is why minutes rather than rounded decimal hours (§7.1). */
    public function test_a_part_hour_is_costed_exactly(): void
    {
        $this->rates->set([], 600, '2026-01-01');

        $this->assertSame(300.0, $this->rates->resolve($this->tower, on: '2026-08-01')->costOf(30));
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_trade_register_renders(): void
    {
        Livewire::test(ListTrades::class)
            ->assertCanSeeTableRecords([$this->steelFixer, $this->mason])
            ->assertSee('Steel fixer');
    }

    public function test_the_worker_register_renders_and_shows_the_engagement(): void
    {
        Worker::create([
            'code' => 'W-020', 'name' => 'Yusuf', 'engagement' => Worker::ENGAGEMENT_SUPPLIED,
        ]);

        Livewire::test(ListWorkers::class)
            ->assertCanSeeTableRecords(Worker::query()->get()->all())
            ->assertSee('Supplied');
    }

    /** The register names each row's scope, which is the column five rate rows are unreadable without. */
    public function test_the_rate_register_names_what_each_row_applies_to(): void
    {
        $this->rates->set([], 500, '2026-01-01');
        $this->rates->set(['trade_id' => $this->steelFixer->getKey()], 650, '2026-01-01');

        Livewire::test(ListLabourRates::class)
            ->assertCanSeeTableRecords(LabourRate::query()->get()->all())
            ->assertSee('Company default')
            ->assertSee('STF');
    }

    /**
     * Creating through the screen goes **through the service**, which is what makes the overlap refusal real.
     *
     * A rule kept in the form is a rule an import or a queue job walks past in silence, and two rates in force for
     * one scope leaves nothing saying which the cost report used.
     */
    public function test_the_create_screen_sets_a_rate_through_the_service(): void
    {
        Livewire::test(CreateLabourRate::class)
            ->fillForm([
                'trade_id' => $this->steelFixer->getKey(),
                'cost_rate_per_hour' => 675,
                'burden_percent' => 18,
                'effective_from' => '2026-02-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rate = LabourRate::query()->firstOrFail();

        $this->assertSame($this->steelFixer->getKey(), $rate->trade_id);
        $this->assertSame(LabourRate::TIER_TRADE, $rate->tier());
        $this->assertEquals(675, $rate->cost_rate_per_hour);
        $this->assertNull($rate->overtime_multiplier, 'left blank, so it falls through the ladder');
    }

    /** And the trade form saves a trade with its usual cost code, which is the only field with a decision in it. */
    public function test_a_trade_can_be_created_with_its_usual_cost_code(): void
    {
        $code = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);

        Livewire::test(CreateTrade::class)
            ->fillForm([
                'code' => 'CARP',
                'name' => 'Carpenter',
                'default_cost_code_id' => $code->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $trade = Trade::query()->where('code', 'CARP')->firstOrFail();

        $this->assertSame($code->getKey(), $trade->default_cost_code_id);
        $this->assertTrue($trade->is_active);
    }
}
