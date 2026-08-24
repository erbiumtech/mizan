<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Pages\JobCostReport;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\ProgressMeasurement;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\Core\Models\CompanyModule;
use Database\Seeders\ConstructionDemoSeeder;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The worked example holds together — `docs/construction-management-plan.md` §3, §5, §7, §14.
 *
 * A demo seeder that silently half-worked would be worse than none: somebody reads the job cost report,
 * believes the numbers and learns the module wrong. So these assert **what the report page returns**, not
 * what the ledger contains. The first version of this test made exactly that mistake — it asserted
 * `sum(amount)` on the steel code and passed, while the report's actual column for that code showed zero,
 * because a goods receipt raises an accrual and the assertion summed every kind together.
 */
class ConstructionDemoSeederTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** The six months the seeder is anchored to. */
    private const PERIODS = ['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01'];

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'demoseeder@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();

        $this->seed(ConstructionDemoSeeder::class);

        $this->job = Job::where('code', 'J-2026-001')->sole();
    }

    private function code(string $code): CostCode
    {
        return CostCode::where('code', $code)->sole();
    }

    /** The report page, as a user sees it at a period. */
    private function report(string $period = '2026-07-01'): JobCostReport
    {
        return Livewire::test(JobCostReport::class)
            ->set('data.job_id', $this->job->getKey())
            ->set('data.period_start', $period)
            ->instance();
    }

    /** @return array<string, mixed> */
    private function row(string $code, string $period = '2026-07-01'): array
    {
        foreach ($this->report($period)->controlRows() as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        $this->fail("{$code} is not on the report.");
    }

    // ------------------------------------------------------------------ the library

    public function test_the_cost_code_library_is_seeded_and_headings_are_not_bookable(): void
    {
        $this->assertGreaterThan(30, CostCode::count());

        // A heading takes no cost: the four-column report rolls children into their parent.
        $this->assertFalse($this->code('01')->is_leaf, 'a group with children must be a branch');
        $this->assertTrue($this->code('01.100')->is_leaf);

        // Land and approvals are ICMS Acquisition; the work is Construction. That split is what carries the
        // distinction the five-value cost_type enum cannot.
        $this->assertSame('A', $this->code('01.100')->icms_category);
        $this->assertSame('A', $this->code('01.200')->icms_category);
        $this->assertSame('C', $this->code('03.100')->icms_category);
    }

    // ------------------------------------------------------------------ date stability

    /**
     * THE regression test. Every cost must land in one of the job's six months.
     *
     * The first version let `GoodsReceiptService::create()` default `received_on` to today, so the steel
     * landed in whatever month the seeder happened to be run in — four periods adrift of the job, and moving
     * every time anybody re-ran it. A demo whose numbers depend on the calendar cannot be checked by anyone.
     */
    public function test_no_cost_lands_outside_the_jobs_own_months(): void
    {
        $periods = CostEntry::query()
            ->where('job_id', $this->job->getKey())
            ->get()
            ->map(fn (CostEntry $e): string => $e->posting_period->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($periods);
        $this->assertEmpty(
            array_diff($periods, self::PERIODS),
            'a cost escaped the seeded calendar: '.implode(', ', array_diff($periods, self::PERIODS))
        );
    }

    // ------------------------------------------------------------------ land and the authority

    public function test_the_land_and_the_authority_show_as_actual_cost(): void
    {
        $this->assertSame(24_650_000.0, (float) $this->row('01.100')['actual'], 'the plot');
        $this->assertSame(1_306_450.0, (float) $this->row('01.110')['actual'], 'transfer duty');
        $this->assertSame(1_742_000.0, (float) $this->row('01.200')['actual'], 'LDA approval');
        $this->assertSame(214_500.0, (float) $this->row('01.210')['actual'], 'scrutiny fee');

        // Budgeted as well as costed. An earlier draft costed the scrutiny fee and forgot to budget it, which
        // read on the report as an overrun that never happened.
        $this->assertSame(200_000.0, (float) $this->row('01.210')['budget']);
    }

    // ------------------------------------------------------------------ order, receive, invoice

    /**
     * §5's three columns kept apart on one cost code.
     *
     * 82 t ordered. 40 t delivered and invoiced, so it is **actual**. The remaining 42 t is still
     * **committed** — promised to the supplier and not available to spend twice. Nothing is accrued, because
     * opening the next period unwound the receipt's accrual once the invoice replaced it.
     */
    public function test_the_steel_is_actual_where_invoiced_and_committed_where_not(): void
    {
        $steel = $this->row('03.200');

        $this->assertSame(40 * 268_000.0, (float) $steel['actual'], '40 t invoiced');
        $this->assertSame(0.0, (float) $steel['accrued'], 'the accrual was unwound by the invoice');
        $this->assertSame(42 * 268_000.0, (float) $steel['committed'], '42 t still on order');
    }

    /**
     * Concrete is the other half of the same rule: received, not yet invoiced, so it is an accrual.
     *
     * §3.5 keeps accrued out of actual on purpose — an accrual is an estimate, and mixing it into actual cost
     * makes the cost performance index move when nothing happened on site.
     */
    public function test_the_concrete_is_accrued_because_no_invoice_has_arrived(): void
    {
        $concrete = $this->row('03.100');

        $this->assertSame(0.0, (float) $concrete['actual']);
        $this->assertSame(300 * 21_800.0, (float) $concrete['accrued'], '300 m³ received at order rate');
    }

    public function test_both_orders_were_issued_rather_than_left_in_draft(): void
    {
        $this->assertSame(2, Commitment::query()->count());

        foreach (Commitment::all() as $order) {
            $this->assertSame(Commitment::TYPE_PURCHASE_ORDER, $order->type);
        }
    }

    // ------------------------------------------------------------------ labour

    /**
     * Twelve crew-days at the rate table: three fixers, four days, the chargehand on two hours of overtime.
     *
     *   normal    8h × 480       = 3,840  per day per worker
     *   overtime  2h × 480 × 1.5 = 1,440  chargehand only
     *   burden    12% of the labour, as its own entry against the same code (§7.3)
     */
    public function test_labour_is_costed_at_the_rate_table(): void
    {
        $onLabour = fn (bool $burden): float => (float) CostEntry::query()
            ->where('job_id', $this->job->getKey())
            ->whereRelation('costCode', 'code', '03.300')
            ->where('is_burden', $burden)
            ->sum('amount');

        $wages = 4 * ((3_840.0 * 3) + 1_440.0);

        $this->assertSame($wages, $onLabour(false), 'wages');
        $this->assertSame(round($wages * 0.12, 2), $onLabour(true), 'burden at 12%');

        $this->assertSame(3, CostEntry::query()
            ->whereRelation('costCode', 'code', '03.300')
            ->distinct()
            ->count('worker_id'));
    }

    // ------------------------------------------------------------------ the four columns

    /**
     * The fourth column exists at all, which is what the first version of this seeder got wrong.
     *
     * Without an issued forecast run, `forecast_final`, `cost_to_complete` and `variance` are null on every
     * row — a quarter of the report simply blank.
     */
    public function test_every_row_carries_all_four_columns(): void
    {
        $rows = $this->report()->controlRows();

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertNotNull($row['forecast_final'], "{$row['code']} has no forecast");
            $this->assertNotNull($row['cost_to_complete'], "{$row['code']} has no cost to complete");
            $this->assertNotNull($row['variance'], "{$row['code']} has no variance");
        }
    }

    public function test_the_totals_forecast_a_small_overrun(): void
    {
        $totals = $this->report()->controlTotals();

        $this->assertSame(71_370_000.0, (float) $totals['budget']);
        $this->assertSame(18_232_000.0, (float) $totals['committed']);
        $this->assertSame(42_772_010.8, (float) $totals['actual']);
        $this->assertSame(6_540_000.0, (float) $totals['accrued']);
        $this->assertSame(72_197_950.0, (float) $totals['forecast_final']);
        // Negative is an overrun: forecast above budget.
        $this->assertSame(-827_950.0, (float) $totals['variance']);
    }

    // ------------------------------------------------------------------ earned value

    /**
     * The earned-value half of the report, which needs a time-phased budget and measured progress.
     *
     * Without `period_start` on the baseline lines the page prints "Schedule performance unavailable" and SPI
     * is null; without progress measurements the earned value is zero and CPI reads 0 — a healthy project
     * looking like a catastrophic one.
     */
    public function test_the_earned_value_metrics_are_available_and_coherent(): void
    {
        $m = $this->report()->metrics();

        $this->assertNull($m['schedule_note'], 'the budget is time-phased, so nothing is unavailable');

        $this->assertSame(71_370_000.0, $m['budget_at_completion']);
        $this->assertSame(48_457_280.0, $m['earned_value']);
        $this->assertSame(71_370_000.0, $m['planned_value']);
        $this->assertSame(67.9, $m['percent_complete']);

        // Behind programme and forecast to overrun: SPI below one, and a negative schedule variance.
        $this->assertSame(0.679, $m['schedule_performance_index']);
        $this->assertSame(-22_912_720.0, $m['schedule_variance']);

        // CPI above one because §14 keeps accruals out of actual cost, and the concrete is not yet invoiced.
        // The accrued column is what a reader checks that against, which is why it is on the report.
        $this->assertSame(1.1329, $m['cost_performance_index']);
    }

    /**
     * Progress follows the deliveries, not the other way round.
     *
     * An earlier draft claimed 92% on steel while only 40 of the 82 t ordered had arrived, and 58% on fixing
     * against 58,061 of a 1,804,000 budget. Earned value *is* physical progress, so a percentage that
     * disagrees with what was delivered is simply wrong — and it inflated CPI to 1.44.
     */
    public function test_progress_is_measured_against_the_deliveries_actually_made(): void
    {
        $this->assertSame(49.0, $this->measurementPercent('03.200'), '40 t delivered of 82 ordered');
        $this->assertSame(48.0, $this->measurementPercent('03.100'), '300 m³ delivered of 620');
        $this->assertSame(100.0, $this->measurementPercent('02.200'), '1,400 m³ of 1,400 excavated');

        // Measured once per code. `earnedValue()` sums every measurement up to the period while each row
        // earns percent × the code's whole budget, so a second row for one code double-counts it.
        $this->assertSame(1, ProgressMeasurement::query()
            ->where('job_id', $this->job->getKey())
            ->whereRelation('costCode', 'code', '03.200')
            ->count());
    }

    private function measurementPercent(string $code): float
    {
        return (float) ProgressMeasurement::query()
            ->where('job_id', $this->job->getKey())
            ->whereRelation('costCode', 'code', $code)
            ->sum('percent_complete');
    }

    // ------------------------------------------------------------------ budget and invariants

    public function test_the_budget_is_time_phased_approved_and_baselined(): void
    {
        $budget = JobBudget::where('job_id', $this->job->getKey())->sole();

        $this->assertSame(JobBudget::KIND_ORIGINAL, $budget->kind);
        $this->assertSame(JobBudget::STATUS_APPROVED, $budget->status);
        $this->assertTrue($budget->is_baseline);
        $this->assertTrue($budget->is_current);

        // Every line is phased, which is what turns planned value on.
        $this->assertSame(0, $budget->lines()->whereNull('period_start')->count());
        $this->assertGreaterThan(1, $budget->lines()->distinct()->count('period_start'));
    }

    public function test_the_job_total_is_the_sum_of_its_entries(): void
    {
        $this->assertSame(
            round((float) CostEntry::where('job_id', $this->job->getKey())->sum('amount'), 2),
            round(app(CostLedger::class)->totalFor($this->job), 2),
            'the invariant of §3.2: the sum of amount over the job is the job cost'
        );

        // The acquisition half alone, which is most of a developer's first year.
        $this->assertSame(27_912_950.0, array_sum([
            (float) $this->row('01.100')['actual'], (float) $this->row('01.110')['actual'],
            (float) $this->row('01.200')['actual'], (float) $this->row('01.210')['actual'],
        ]));
    }

    public function test_running_it_twice_does_not_duplicate_the_job(): void
    {
        $before = app(CostLedger::class)->totalFor($this->job);

        $this->seed(ConstructionDemoSeeder::class);

        $this->assertSame(1, Job::where('code', 'J-2026-001')->count());
        $this->assertSame($before, app(CostLedger::class)->totalFor($this->job->refresh()));
    }
}
