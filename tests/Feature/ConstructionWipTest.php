<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Pages\WipReport;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\ForecastRun;
use App\Modules\ConstructionCosting\Models\WipSnapshot;
use App\Modules\ConstructionCosting\Policies\WipSnapshotPolicy;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\WipService;
use App\Modules\Core\Models\CompanyModule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Work in progress, and the loss that must be taken at once — §4.4, Phase 11d.
 *
 * §4.4 is the one place this plan permits a stored total, and it argues the exception against
 * `docs/new-module-checklist.md` §10 rather than assuming it: **"a WIP position is a judgement at a point in time — the
 * surveyor's forecast, the surveyed percentage, the loss provision — not a derivation from immutable facts."** Recomputing
 * last March with today's forecast would restate a month that was signed off, reported to a bank and used to compute a
 * bonus.
 *
 * Four rules under test:
 *
 *  - **Unlocked recomputes, locked is frozen.** The whole of the exception, made operational by one column.
 *  - **Approved variations only in contract value; pending shown beside it and excluded.** The pair is the point.
 *  - **The whole expected loss, immediately**, never pro-rated — the commonest way a loss-making contract reports as
 *    profitable until the month it finishes.
 *  - **The journal posts the movement, not the balance**, and §4.4 forbids having the choice twice.
 */
class ConstructionWipTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labour;

    private CostLedger $ledger;

    private WipService $wip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'wip@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create([
            'code' => 'J-1',
            'name' => 'Tower',
            'status' => Job::STATUS_IN_PROGRESS,
            'percent_complete_method' => WipSnapshot::METHOD_COST_TO_COST,
        ]);
        $this->labour = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);

        $this->ledger = app(CostLedger::class);
        $this->wip = app(WipService::class);
    }

    private function cost(float $amount, string $incurredOn = '2026-08-10', array $attributes = []): CostEntry
    {
        return $this->ledger->record($this->job, $this->labour, array_merge([
            'amount' => $amount,
            'incurred_on' => $incurredOn,
            'gl_treatment' => CostEntry::GL_MIRRORED,
            'description' => 'Site labour',
        ], $attributes));
    }

    /** A receivable contract on the job, which is where §4.4's revenue side comes from. */
    private function contract(float $sum): int
    {
        return DB::table('construction_contracts')->insertGetId([
            'job_id' => $this->job->getKey(),
            'side' => 'receivable',
            'contract_number' => 'C-'.uniqid(),
            'title' => 'Main contract',
            'contract_sum' => $sum,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function variation(int $contractId, float $amount, string $status, bool $provisional = false): void
    {
        DB::table('construction_variations')->insert([
            'contract_id' => $contractId,
            'variation_number' => 'VO-'.uniqid(),
            'title' => 'Extra works',
            'status' => $status,
            'approved_amount' => $amount,
            'is_price_provisional' => $provisional,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A certificate, and **the period end is a parameter** because it decides which month sees the billing.
     *
     * The first draft hardcoded August, which meant a July position saw no billings at all and every "nothing moved"
     * test moved. Billings to date are read to the end of the month being computed, so a certificate is either in a
     * month or after it.
     */
    private function certificate(
        int $contractId,
        float $grossToDate,
        string $status = 'issued',
        int $sequence = 1,
        string $periodEnd = '2026-08-31',
    ): void {
        DB::table('construction_payment_certificates')->insert([
            'contract_id' => $contractId,
            'certificate_number' => 'IPC-'.$sequence,
            'sequence' => $sequence,
            'period_end' => $periodEnd,
            'status' => $status,
            'gross_value_to_date' => $grossToDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** An issued forecast, which is §4.4's "estimated total cost". */
    private function forecast(float $finalCost, string $periodStart = '2026-08-01'): ForecastRun
    {
        $run = ForecastRun::create([
            'job_id' => $this->job->getKey(),
            'period_start' => $periodStart,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        DB::table('construction_forecast_lines')->insert([
            'forecast_run_id' => $run->getKey(),
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $this->labour->getKey(),
            'forecast_final_cost' => $finalCost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $run;
    }

    // ---------------------------------------------------------------- the position

    /**
     * **The plain case, and every figure in it is §4.4's.**
     *
     * A 10,000,000 contract, 4,000,000 spent, forecast to finish at 8,000,000, 3,000,000 billed. Cost-to-cost puts it at
     * 50%, so 5,000,000 earned against 3,000,000 billed leaves a 2,000,000 contract asset.
     */
    public function test_a_position_is_computed_from_cost_forecast_and_billings(): void
    {
        $contract = $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $this->certificate($contract, 3_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('50.0000', $snapshot->percent_complete);
        $this->assertSame('10000000.00', $snapshot->contract_value);
        $this->assertSame('5000000.00', $snapshot->revenue_recognised);
        $this->assertSame('3000000.00', $snapshot->billings_to_date);
        $this->assertSame('2000000.00', $snapshot->contract_asset);
        $this->assertSame('0.00', $snapshot->contract_liability);
    }

    /**
     * **Over-billed is a contract liability, and it is a separate column.**
     *
     * Two columns rather than one signed figure, because a sign convention is something somebody gets backwards — and
     * these are opposite sides of the balance sheet.
     */
    public function test_over_billing_produces_a_contract_liability(): void
    {
        $contract = $this->contract(10_000_000);
        $this->cost(2_000_000);
        $this->forecast(8_000_000);
        $this->certificate($contract, 4_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('2500000.00', $snapshot->revenue_recognised, '25% of 10,000,000');
        $this->assertSame('1500000.00', $snapshot->contract_liability);
        $this->assertSame('0.00', $snapshot->contract_asset);
    }

    /** Accrued cost counts: a delivery received and not invoiced is cost incurred. */
    public function test_accrued_cost_is_part_of_the_position(): void
    {
        $this->contract(10_000_000);
        $this->cost(3_000_000);
        $this->cost(1_000_000, '2026-08-12', ['kind' => CostEntry::KIND_ACCRUAL, 'gl_purpose' => 'grni']);
        $this->forecast(8_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('3000000.00', $snapshot->cost_to_date);
        $this->assertSame('1000000.00', $snapshot->accrued_to_date);
        $this->assertSame(4_000_000.0, $snapshot->totalCost());
        $this->assertSame('50.0000', $snapshot->percent_complete, 'and it moves the percentage');
    }

    /** Cost to date is cumulative: a WIP position is a balance-sheet figure, not a month's activity. */
    public function test_cost_to_date_is_cumulative_across_months(): void
    {
        $this->contract(10_000_000);
        $this->cost(1_000_000, '2026-06-10');
        $this->cost(3_000_000, '2026-08-10');
        $this->forecast(8_000_000);

        $this->assertSame('4000000.00', $this->wip->compute($this->job, '2026-08-01')->cost_to_date);
    }

    /** A memo cost is outside it, for the reason it is outside everything else that reaches the accounts. */
    public function test_a_memo_cost_is_outside_the_position(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000, '2026-08-10', ['gl_treatment' => CostEntry::GL_MEMO]);
        $this->forecast(8_000_000);

        $this->assertSame('0.00', $this->wip->compute($this->job, '2026-08-01')->cost_to_date);
    }

    /**
     * **A job with no method chosen has no position, and the refusal says why.**
     *
     * §4.4 makes the method a per-job choice, and choosing for a company would pick cost-to-cost — which reports *more*
     * progress the more a job overspends, so the default would flatter exactly the job that needs watching.
     */
    public function test_a_job_with_no_method_is_refused_with_the_reason(): void
    {
        $this->job->update(['percent_complete_method' => null]);
        $this->contract(10_000_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no percent-complete method');
        $this->wip->compute($this->job->fresh(), '2026-08-01');
    }

    /**
     * No contract, no revenue side — and the job's own `contract_sum` is deliberately not a fallback.
     *
     * §8 makes the contract the document that carries the sum. A WIP position computed off a figure typed on the job
     * would disagree with every certificate issued against the contract.
     */
    public function test_a_job_with_no_contract_has_no_revenue_side(): void
    {
        $this->job->update(['contract_sum' => 9_000_000]);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $snapshot = $this->wip->compute($this->job->fresh(), '2026-08-01');

        $this->assertSame('0.00', $snapshot->contract_value);
        $this->assertSame('0.00', $snapshot->revenue_recognised);
    }

    // ---------------------------------------------------------------- variations

    /** **Approved variations are in the contract value.** */
    public function test_approved_variations_raise_the_contract_value(): void
    {
        $contract = $this->contract(10_000_000);
        $this->variation($contract, 2_000_000, 'approved');
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('2000000.00', $snapshot->variations_approved);
        $this->assertSame('12000000.00', $snapshot->contract_value);
    }

    /**
     * **Pending variations are shown and excluded**, which §4.4 asks for as a pair.
     *
     * A job whose contract value looks comfortable while eleven million of variations sit unapproved is a job about to be
     * in trouble, and one figure cannot say so.
     */
    public function test_pending_variations_are_shown_beside_the_contract_value_and_not_in_it(): void
    {
        $contract = $this->contract(10_000_000);
        $this->variation($contract, 11_000_000, 'submitted');
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('11000000.00', $snapshot->variations_pending);
        $this->assertSame('10000000.00', $snapshot->contract_value, 'and not one rupee of it is in here');
    }

    /**
     * **A variation approved at a provisional price is pending, not approved.**
     *
     * §9's reason: the scope is agreed and the money is not. Putting it in contract value would recognise revenue at a
     * rate that may not survive the argument — which is exactly the state §4.4 wants visible *beside* the value.
     */
    public function test_a_provisionally_priced_variation_counts_as_pending(): void
    {
        $contract = $this->contract(10_000_000);
        $this->variation($contract, 3_000_000, 'approved', provisional: true);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('0.00', $snapshot->variations_approved);
        $this->assertSame('3000000.00', $snapshot->variations_pending);
    }

    /** A rejected variation is neither. */
    public function test_a_rejected_variation_is_in_neither_column(): void
    {
        $contract = $this->contract(10_000_000);
        $this->variation($contract, 3_000_000, 'rejected');
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('0.00', $snapshot->variations_approved);
        $this->assertSame('0.00', $snapshot->variations_pending);
    }

    // ---------------------------------------------------------------- the loss

    /**
     * **The whole expected loss, immediately.** §4.4, and this is the test that says never pro-rated.
     *
     * A 10,000,000 contract forecast to cost 13,000,000, one per cent complete. The whole 3,000,000 is recognised now,
     * not 30,000 of it.
     */
    public function test_the_whole_expected_loss_is_recognised_at_one_per_cent_complete(): void
    {
        $this->contract(10_000_000);
        $this->cost(130_000);
        $this->forecast(13_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame('1.0000', $snapshot->percent_complete);
        $this->assertSame('3000000.00', $snapshot->provision_for_loss, 'the whole loss, not 30,000');
        $this->assertTrue($snapshot->isLossMaking());
    }

    /** And it is the same figure at 96% complete: the provision does not depend on progress. */
    public function test_the_loss_is_the_same_figure_late_in_the_job(): void
    {
        $this->contract(10_000_000);
        $this->cost(12_480_000);
        $this->forecast(13_000_000);

        $this->assertSame('3000000.00', $this->wip->compute($this->job, '2026-08-01')->provision_for_loss);
    }

    /**
     * **A recognised loss reduces the contract asset**, because a loss recognised is not an asset.
     *
     * That is what recognising it means. Leaving the asset gross and the provision beside it would report the same money
     * twice on two lines of one balance sheet.
     */
    public function test_the_provision_reduces_the_contract_asset(): void
    {
        $contract = $this->contract(10_000_000);
        $this->cost(5_000_000);
        $this->forecast(13_000_000);
        $this->certificate($contract, 1_000_000);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        // 5,000,000 of 13,000,000 is 38.4615% complete, so 3,846,150 of the 10,000,000 contract is earned. Less the
        // whole 3,000,000 loss leaves 846,150 against 1,000,000 billed — the job is over-billed once the loss is taken,
        // which is the point: recognising a loss can turn an apparent asset into a liability in one month.
        $this->assertSame('3846150.00', $snapshot->revenue_recognised);
        $this->assertSame('3000000.00', $snapshot->provision_for_loss);
        $this->assertSame('153850.00', $snapshot->contract_liability);
        $this->assertSame('0.00', $snapshot->contract_asset);
    }

    /** A profitable job has no provision. */
    public function test_a_profitable_job_carries_no_provision(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $this->assertSame('0.00', $this->wip->compute($this->job, '2026-08-01')->provision_for_loss);
    }

    /**
     * **Only issued forecasts count.**
     *
     * A draft is a surveyor's working paper. A WIP position built on one would restate itself every time they saved,
     * which is the failure `locked_at` exists to prevent one level up.
     */
    public function test_a_draft_forecast_is_not_the_estimated_total_cost(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $run = $this->forecast(13_000_000);
        $run->update(['status' => 'draft', 'issued_at' => null]);

        $snapshot = $this->wip->compute($this->job, '2026-08-01');

        // Falls back to cost to date, which is conservative rather than optimistic: it says "we know of no cost beyond
        // what we have spent" and recognises no loss it cannot see.
        $this->assertSame('4000000.00', $snapshot->forecast_final_cost);
        $this->assertSame('0.00', $snapshot->provision_for_loss);
        $this->assertNull($snapshot->forecast_run_id);
    }

    /** The latest issued forecast at or before the month, because June's best estimate is what June had. */
    public function test_the_forecast_is_the_latest_issued_at_or_before_the_month(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(9_000_000, '2026-06-01');
        $this->forecast(8_000_000, '2026-08-01');
        $this->forecast(7_000_000, '2026-10-01');

        $this->assertSame('8000000.00', $this->wip->compute($this->job, '2026-08-01')->forecast_final_cost);
    }

    // ---------------------------------------------------------------- locking

    /**
     * **Unlocked recomputes; locked is frozen.** §4.4's exception, made operational.
     */
    public function test_an_unlocked_position_recomputes_and_a_locked_one_does_not(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $this->wip->compute($this->job, '2026-08-01');
        $this->cost(1_000_000, '2026-08-20');

        $this->assertSame('5000000.00', $this->wip->compute($this->job, '2026-08-01')->cost_to_date, 'unlocked moved');

        $this->wip->lock($this->job, '2026-08-01');
        $this->cost(2_000_000, '2026-08-25');

        $this->assertSame(
            '5000000.00',
            $this->wip->compute($this->job, '2026-08-01')->cost_to_date,
            'locked did not — the month was signed off, reported to a bank and used to compute a bonus',
        );
    }

    /** Locking recomputes first, so it never freezes figures that were true a fortnight ago. */
    public function test_locking_recomputes_before_it_freezes(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $this->wip->compute($this->job, '2026-08-01');

        $this->cost(1_000_000, '2026-08-20');
        $snapshot = $this->wip->lock($this->job, '2026-08-01');

        $this->assertSame('5000000.00', $snapshot->cost_to_date);
        $this->assertTrue($snapshot->isLocked());
    }

    /** A locked month is locked once. */
    public function test_a_locked_month_cannot_be_locked_again(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $this->wip->lock($this->job, '2026-08-01');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already locked');
        $this->wip->lock($this->job, '2026-08-01');
    }

    /** One position per job per month — the constraint is the feature. */
    public function test_one_position_per_job_per_month(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $this->wip->compute($this->job, '2026-08-01');
        $this->wip->compute($this->job, '2026-08-01');

        $this->assertSame(1, WipSnapshot::query()->forPeriod('2026-08-01')->count());
    }

    // ---------------------------------------------------------------- the movement

    /**
     * **The journal posts the movement from the previous locked snapshot, not the balance** — §4.4's settled choice.
     *
     * July's position 2,000,000 and August's 3,500,000 posts 1,500,000, not 3,500,000. Posting the balance produces a
     * profit and loss whose gross figures are enormous and whose monthly movement has to be inferred.
     */
    public function test_the_journal_posts_the_movement_and_not_the_balance(): void
    {
        $this->nominateWipAccounts();
        $contract = $this->contract(10_000_000);
        // Issued in July, because July is the first month locked. A forecast issued in August would leave July with
        // none, and the cost-to-date fallback would then read as 100% complete — which is right as a fallback and wrong
        // as a fixture.
        $this->forecast(8_000_000, '2026-07-01');

        // July: 2,000,000 asset.
        $this->cost(3_200_000, '2026-07-10');
        $this->certificate($contract, 2_000_000, sequence: 1, periodEnd: '2026-07-31');
        $july = $this->wip->lock($this->job, '2026-07-01');
        $this->assertSame(2_000_000.0, $july->netPosition());

        // August: another 1,200,000 of cost, nothing more billed.
        $this->cost(1_200_000, '2026-08-10');
        $august = $this->wip->lock($this->job, '2026-08-01');

        $movement = $this->wip->movement($august);
        $this->assertSame(2_000_000.0, $movement['from']);
        $this->assertSame(3_500_000.0, $movement['to']);
        $this->assertSame(1_500_000.0, $movement['movement']);

        $entry = $this->wip->postMovement($august);

        $this->assertSame('1500000.00', $entry->lines->firstWhere('debit_amount', '>', 0)->debit_amount);
        $this->assertTrue($entry->isBalanced());
        $this->assertTrue($entry->is_posted);
    }

    /** Dated to the month end, not today: a June position posted in July belongs in June. */
    public function test_the_movement_journal_is_dated_to_the_month_end(): void
    {
        $this->nominateWipAccounts();
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $this->travelTo('2026-09-04 10:00');

        $entry = $this->wip->postMovement($this->wip->lock($this->job, '2026-08-01'));

        $this->assertSame('2026-08-31', $entry->entry_date->toDateString());
    }

    /**
     * A month whose position has not moved posts nothing.
     *
     * A zero-value journal line is a line in the accounts saying nothing happened, which is worse than the silence it
     * replaces — the same argument §11a makes about a cancelling group.
     */
    public function test_a_month_with_no_movement_posts_nothing(): void
    {
        $this->nominateWipAccounts();
        $contract = $this->contract(10_000_000);
        $this->forecast(8_000_000, '2026-07-01');
        $this->cost(3_200_000, '2026-07-10');
        $this->certificate($contract, 2_000_000, periodEnd: '2026-07-31');
        $this->wip->lock($this->job, '2026-07-01');

        // August: nothing happened at all.
        $august = $this->wip->lock($this->job, '2026-08-01');

        $this->assertNull($this->wip->postMovement($august));
        $this->assertFalse($august->fresh()->isPosted());
    }

    /** A falling position swaps the sides rather than writing a negative debit. */
    public function test_a_falling_position_swaps_the_sides(): void
    {
        $this->nominateWipAccounts();
        $contract = $this->contract(10_000_000);
        $this->forecast(8_000_000, '2026-07-01');
        $this->cost(4_000_000, '2026-07-10');
        $this->wip->lock($this->job, '2026-07-01');

        // August: the client is billed, so the asset falls.
        $this->certificate($contract, 4_000_000);
        $august = $this->wip->lock($this->job, '2026-08-01');

        $entry = $this->wip->postMovement($august);
        $wipAccountId = (int) ControlAccount::forPurpose('wip_movement')->account_id;

        $this->assertSame('4000000.00', $entry->lines->firstWhere('account_id', $wipAccountId)->credit_amount);
        $this->assertSame('0.00', $entry->lines->firstWhere('account_id', $wipAccountId)->debit_amount);
    }

    /** An unlocked position is not postable: a journal against a figure that moves is a journal nobody can explain. */
    public function test_an_unlocked_position_cannot_be_posted(): void
    {
        $this->nominateWipAccounts();
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('posted when it is locked');
        $this->wip->postMovement($this->wip->compute($this->job, '2026-08-01'));
    }

    /** Nor twice. */
    public function test_a_position_is_posted_once(): void
    {
        $this->nominateWipAccounts();
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $snapshot = $this->wip->lock($this->job, '2026-08-01');
        $this->wip->postMovement($snapshot);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been posted');
        $this->wip->postMovement($snapshot->fresh());
    }

    /**
     * **Without the accounts nominated, posting is refused rather than crediting a suspense account.**
     *
     * §4.1's principle: "a summary posting without that link is a number in the accounts nobody can explain, and should
     * be treated as a defect rather than a shortcut."
     */
    public function test_posting_without_the_accounts_nominated_is_refused(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $snapshot = $this->wip->lock($this->job, '2026-08-01');

        $before = JournalEntryLine::query()->count();

        try {
            $this->wip->postMovement($snapshot);
            $this->fail('posting without the accounts should refuse');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nominated under Control accounts', $e->getMessage());
        }

        $this->assertSame($before, JournalEntryLine::query()->count(), 'and no suspense line was written');
    }

    // ---------------------------------------------------------------- the methods

    /**
     * **Surveyed progress is weighted by budget at completion**, not averaged.
     *
     * A simple mean would let a 20,000 cost code count as much as a 20,000,000 one, and on a job with many small codes
     * that is most of the answer.
     */
    public function test_surveyed_progress_is_weighted_by_budget(): void
    {
        $this->job->update(['percent_complete_method' => WipSnapshot::METHOD_SURVEYED]);
        $small = CostCode::create([
            'code' => '01.100', 'name' => 'Site setup', 'cost_type' => CostCode::TYPE_OTHER, 'unit' => 'item',
        ]);
        $this->contract(10_000_000);

        DB::table('construction_progress_measurements')->insert([
            [
                'job_id' => $this->job->getKey(), 'cost_code_id' => $this->labour->getKey(),
                'period_start' => '2026-08-01', 'method' => 'units_completed',
                'percent_complete' => 40, 'budget_at_completion' => 9_000_000,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'job_id' => $this->job->getKey(), 'cost_code_id' => $small->getKey(),
                'period_start' => '2026-08-01', 'method' => 'units_completed',
                'percent_complete' => 100, 'budget_at_completion' => 1_000_000,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // Weighted: (9,000,000 × 40 + 1,000,000 × 100) / 10,000,000 = 46%. A simple mean would say 70%.
        $this->assertSame('46.0000', $this->wip->compute($this->job->fresh(), '2026-08-01')->percent_complete);
    }

    /**
     * **A milestone is achieved when the certifier says so.**
     *
     * Not when the contractor says so, which is why achievement is read from certified value rather than from a flag
     * somebody on site can set.
     */
    public function test_milestone_progress_comes_from_certified_value(): void
    {
        $this->job->update(['percent_complete_method' => WipSnapshot::METHOD_MILESTONE]);
        $contract = $this->contract(10_000_000);

        $items = [];

        foreach ([['M1', 4_000_000], ['M2', 6_000_000]] as [$no, $value]) {
            $items[$no] = DB::table('construction_contract_items')->insertGetId([
                'contract_id' => $contract,
                'item_no' => $no,
                'description' => "Milestone {$no}",
                'item_type' => 'milestone',
                'scheduled_value' => $value,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $certId = DB::table('construction_payment_certificates')->insertGetId([
            'contract_id' => $contract,
            'certificate_number' => 'IPC-1',
            'sequence' => 1,
            'period_end' => '2026-08-31',
            'status' => 'issued',
            'gross_value_to_date' => 4_000_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('construction_certificate_lines')->insert([
            'payment_certificate_id' => $certId,
            'contract_item_id' => $items['M1'],
            'item_no' => 'M1',
            'description' => 'Milestone M1',
            'cumulative_work_value' => 4_000_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('40.0000', $this->wip->compute($this->job->fresh(), '2026-08-01')->percent_complete);
    }

    /**
     * A milestone job with no milestones is at nought, not silently switched to cost-to-cost.
     *
     * Quietly switching would report progress the contract does not recognise.
     */
    public function test_a_milestone_job_with_no_milestones_is_at_nothing(): void
    {
        $this->job->update(['percent_complete_method' => WipSnapshot::METHOD_MILESTONE]);
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);

        $this->assertSame('0.0000', $this->wip->compute($this->job->fresh(), '2026-08-01')->percent_complete);
    }

    /** Cost-to-cost is capped at 100%: a job spending twice its forecast is not 200% built. */
    public function test_cost_to_cost_is_capped_at_a_hundred(): void
    {
        $this->contract(10_000_000);
        $this->cost(16_000_000);
        $this->forecast(8_000_000);

        $this->assertSame('100.0000', $this->wip->compute($this->job, '2026-08-01')->percent_complete);
    }

    /** And the method the figure came from is snapshotted on the row, because a percentage without it is undefendable. */
    public function test_the_method_is_snapshotted_with_the_percentage(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $snapshot = $this->wip->lock($this->job, '2026-08-01');

        $this->job->update(['percent_complete_method' => WipSnapshot::METHOD_SURVEYED]);

        $this->assertSame(WipSnapshot::METHOD_COST_TO_COST, $snapshot->fresh()->percent_complete_method);
    }

    // ---------------------------------------------------------------- permissions and the page

    /**
     * **Locking is `ConstructionPeriodClose` and posting is `ConstructionGlPost`.**
     *
     * Asserted against the policy class rather than the gate, because `Gate::before` makes an Administrator pass every
     * ability and the assertion would then be about nothing.
     */
    public function test_locking_and_posting_are_the_grants_the_plan_puts_them_on(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $unlocked = $this->wip->compute($this->job, '2026-08-01');
        $policy = app(WipSnapshotPolicy::class);

        $surveyor = $this->makeUser('Accountant', 'surveyor3@test.local');
        $manager = $this->makeUser('Manager', 'closer2@test.local');

        $this->assertTrue($policy->create($surveyor), 'computing is a read');
        $this->assertFalse($policy->lock($surveyor, $unlocked));
        $this->assertTrue($policy->lock($manager, $unlocked));

        $locked = $this->wip->lock($this->job, '2026-08-01');
        $this->assertFalse($policy->post($surveyor, $locked));
        $this->assertTrue($policy->post($manager, $locked));
    }

    /** A snapshot is never edited and never deleted. */
    public function test_a_snapshot_is_neither_editable_nor_deletable(): void
    {
        $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $snapshot = $this->wip->compute($this->job, '2026-08-01');
        $policy = app(WipSnapshotPolicy::class);
        $ceo = $this->makeUser('CEO', 'ceo3@test.local');

        $this->assertFalse($policy->update($ceo, $snapshot));
        $this->assertFalse($policy->delete($ceo, $snapshot));
    }

    /** The report renders and carries both positions. */
    public function test_the_report_renders_both_positions(): void
    {
        $contract = $this->contract(10_000_000);
        $this->cost(4_000_000);
        $this->forecast(8_000_000);
        $this->certificate($contract, 3_000_000);
        $this->wip->compute($this->job, '2026-08-01');

        Livewire::test(WipReport::class)
            ->set('data.period_start', '2026-08-01')
            ->assertSuccessful()
            ->assertSee('Contract asset')
            ->assertSee('Contract liability')
            ->assertSee('Cost to cost');
    }

    /**
     * A live job with no position is **named** rather than left out.
     *
     * A WIP report missing a job is a balance sheet missing a contract, and the usual reason is a percent-complete
     * method nobody has chosen — a two-second fix somebody has to be told about.
     */
    public function test_the_report_names_a_live_job_with_no_position(): void
    {
        $this->contract(10_000_000);
        Job::create([
            'code' => 'J-2',
            'name' => 'Annexe',
            'status' => Job::STATUS_IN_PROGRESS,
        ]);

        Livewire::test(WipReport::class)
            ->set('data.period_start', '2026-08-01')
            ->assertSee('Live jobs with no position this month')
            ->assertSee('no percent-complete method chosen');
    }

    /**
     * **Nothing in this service names a `ConstructionContracts` class.**
     *
     * `construction_contracts` already declares an edge to `construction_costing`, so an import the other way would be a
     * two-cycle and `TANGLED_MODULE_BUDGET = 0` would be right to fail. Asserted rather than trusted, because the
     * failure is a boundary test somebody would be tempted to appease by raising a budget.
     */
    public function test_the_wip_service_names_no_contracts_class(): void
    {
        $source = file_get_contents(base_path('app/Modules/ConstructionCosting/Services/WipService.php'));

        $this->assertStringNotContainsString('App\\Modules\\ConstructionContracts', $source);
        $this->assertStringContainsString("'construction_variations'", $source);
        $this->assertStringContainsString("'construction_payment_certificates'", $source);
    }

    /** Nominate the two accounts a WIP movement needs. */
    private function nominateWipAccounts(): void
    {
        ControlAccount::create([
            'account_id' => Account::firstOrCreate(['code' => '1360'], ['name' => 'Work in progress', 'type' => 'asset'])->getKey(),
            'kind' => ControlAccount::KIND_WIP,
            'purpose' => 'wip_movement',
        ]);
        ControlAccount::create([
            'account_id' => Account::firstOrCreate(['code' => '4300'], ['name' => 'Contract revenue', 'type' => 'income'])->getKey(),
            'kind' => ControlAccount::KIND_REVENUE,
        ]);
    }
}
