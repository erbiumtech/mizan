<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\CostBatch;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The job-cost ledger — `docs/construction-management-plan.md` §3, Phase 2.
 *
 * **The invariant is what everything here defends**: the sum of `amount` over a job's entries, filtered by
 * nothing but the period, *is* the job's cost. No `is_active`, no soft delete, no current-version flag — "a flag
 * that must be filtered is a flag somebody forgets, and the query that forgets it is a cost report that is wrong
 * and looks fine". Phase 2's stated exit condition is that this holds under a test that reverses and re-books,
 * which is `test_the_invariant_survives_a_reversal_and_a_rebooking` below.
 *
 * The other rule with money behind it is §3.4's: a closed period refuses new cost, and a late invoice is not an
 * error — it lands in the open period with `incurred_on` preserved and a flag, because reopening a signed-off
 * month invalidates the WIP snapshot, the client certificate and the GL summary that all depended on that
 * period's total.
 */
class ConstructionCostLedgerTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labour;

    private CostCode $material;

    private CostLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'costledger@test.local'));
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
        $this->ledger = app(CostLedger::class);
    }

    private function record(CostCode $code, float $amount, array $attributes = []): CostEntry
    {
        return $this->ledger->record($this->job, $code, array_merge([
            'amount' => $amount,
            'incurred_on' => '2026-08-10',
            'description' => 'Test cost',
        ], $attributes));
    }

    // ---------------------------------------------------------------- recording

    public function test_a_cost_is_recorded_against_a_job_and_a_code(): void
    {
        $entry = $this->record($this->labour, 250_000, ['quantity' => 12.5, 'unit_of_measure' => 't', 'unit_rate' => 20_000]);

        $this->assertSame('250000.00', $entry->amount);
        $this->assertSame(CostEntry::KIND_ACTUAL, $entry->kind);
        $this->assertSame('2026-08-01', $entry->posting_period->toDateString(), 'the period is the first of the month');
    }

    /**
     * `cost_type` is snapshotted, not read through the code.
     *
     * Re-typing a cost code in June must not restate March's labour/material split — the same reasoning that
     * stores `invoice_lines.tax_amount` rather than recomputing it.
     */
    public function test_the_cost_type_is_snapshotted_and_survives_a_recoded_library(): void
    {
        $entry = $this->record($this->labour, 100_000);

        $this->assertSame(CostCode::TYPE_LABOUR, $entry->cost_type);

        $this->labour->update(['cost_type' => CostCode::TYPE_SUBCONTRACT]);

        $this->assertSame(
            CostCode::TYPE_LABOUR,
            $entry->fresh()->cost_type,
            'retyping the code restated history, so the labour/material split moved for a closed month',
        );
    }

    /** A heading is not bookable: cost against it would double-count in every rolled-up total. */
    public function test_cost_cannot_be_booked_against_a_heading(): void
    {
        $heading = CostCode::create(['code' => '02', 'name' => 'Concrete works']);
        CostCode::create(['code' => '02.900', 'name' => 'Child', 'parent_id' => $heading->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is a heading/');

        $this->record($heading->fresh(), 1000);
    }

    public function test_cost_cannot_be_booked_against_a_switched_off_code(): void
    {
        $this->labour->update(['is_active' => false]);

        $this->expectExceptionMessageMatches('/switched off/');

        $this->record($this->labour->fresh(), 1000);
    }

    /**
     * `gl_treatment` defaults to pending and is a column, never a null to interpret.
     *
     * `pending` (should reach the GL, has not) and `memo` (deliberately never will) look identical as a null
     * `journal_entry_id`, and a null meaning "we do not know which" is how a sub-ledger drifts for a year.
     */
    public function test_gl_treatment_distinguishes_pending_from_memo(): void
    {
        $pending = $this->record($this->labour, 100_000);
        $memo = $this->record($this->labour, 5_000, ['gl_treatment' => CostEntry::GL_MEMO, 'is_burden' => true]);

        $this->assertSame(CostEntry::GL_PENDING, $pending->gl_treatment);
        $this->assertTrue($memo->isMemoOnly());

        $this->assertSame([100_000.0], CostEntry::query()->awaitingGl()->pluck('amount')->map('floatval')->all());
        $this->assertSame([5_000.0], CostEntry::query()->memoOnly()->pluck('amount')->map('floatval')->all());
    }

    // ---------------------------------------------------------------- the invariant

    /**
     * **Phase 2's exit condition.** The sum of `amount` is the cost, through a reversal and a re-booking.
     *
     * A reversal is a negative row that cancels its original *in the same sum*, which is what lets the cost
     * report be a single `group by` rather than a pipeline of adjustments — and what makes it impossible to
     * forget a flag and produce a total that is wrong and looks fine.
     */
    public function test_the_invariant_survives_a_reversal_and_a_rebooking(): void
    {
        // Booked wrong.
        $wrong = $this->record($this->labour, 250_000, ['quantity' => 12.5]);
        $this->assertSame(250_000.0, $this->ledger->totalFor($this->job));

        // Reversed.
        $this->ledger->reverse($wrong, 'Coded to the wrong job');
        $this->assertSame(
            0.0,
            $this->ledger->totalFor($this->job),
            'a reversal must cancel its original in the same unfiltered sum',
        );

        // Re-booked correctly.
        $this->record($this->material, 180_000, ['quantity' => 90]);
        $this->assertSame(180_000.0, $this->ledger->totalFor($this->job));

        // And all three rows are still on the ledger: nothing was deleted or flagged away.
        $this->assertSame(3, CostEntry::query()->count(), 'history was destroyed rather than added to');
    }

    /** The quantity reverses too, or the unit rate on the report is computed from a stale denominator. */
    public function test_a_reversal_negates_the_quantity_as_well_as_the_amount(): void
    {
        $entry = $this->record($this->labour, 250_000, ['quantity' => 12.5]);

        $reversal = $this->ledger->reverse($entry);

        $this->assertSame('-250000.00', $reversal->amount);
        $this->assertSame('-12.5000', $reversal->quantity);
    }

    /** The pair points both ways, so "was this corrected" is one column rather than a search. */
    public function test_a_reversal_links_both_ways(): void
    {
        $entry = $this->record($this->labour, 100_000);
        $reversal = $this->ledger->reverse($entry);

        $this->assertSame($entry->getKey(), $reversal->reverses_id);
        $this->assertSame($reversal->getKey(), $entry->fresh()->reversed_by_id);
        $this->assertTrue($entry->fresh()->isReversed());
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $entry = $this->record($this->labour, 100_000);
        $this->ledger->reverse($entry);

        $this->expectExceptionMessageMatches('/already been reversed/');

        $this->ledger->reverse($entry->fresh());
    }

    /** Two negations of one cost read as a credit nobody can explain. */
    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $entry = $this->record($this->labour, 100_000);
        $reversal = $this->ledger->reverse($entry);

        $this->expectExceptionMessageMatches('/cannot itself be reversed/');

        $this->ledger->reverse($reversal);
    }

    /**
     * A reversal keeps the original's cost type, not the code's as it now stands.
     *
     * Otherwise a reclassified code would leave the pair failing to cancel within the labour/material split —
     * the totals would net to zero while both halves of the split were wrong.
     */
    public function test_a_reversal_keeps_the_originals_cost_type(): void
    {
        $entry = $this->record($this->labour, 100_000);
        $this->labour->update(['cost_type' => CostCode::TYPE_SUBCONTRACT]);

        $reversal = $this->ledger->reverse($entry->fresh());

        $this->assertSame(CostCode::TYPE_LABOUR, $reversal->cost_type);
    }

    /** A memo cost reverses as a memo: a reversal that suddenly needed posting would create a phantom liability. */
    public function test_a_memo_cost_reverses_as_a_memo(): void
    {
        $entry = $this->record($this->labour, 5_000, ['gl_treatment' => CostEntry::GL_MEMO]);

        $this->assertSame(CostEntry::GL_MEMO, $this->ledger->reverse($entry)->gl_treatment);
    }

    // ---------------------------------------------------------------- periods

    public function test_a_period_is_created_on_the_first_cost_of_the_month(): void
    {
        $this->record($this->labour, 100_000, ['incurred_on' => '2026-08-10']);

        $period = CostPeriod::query()->firstOrFail();

        $this->assertSame('2026-08-01', $period->period_start->toDateString());
        $this->assertSame('2026-08-31', $period->period_end->toDateString());
        $this->assertTrue($period->isOpen());
    }

    /**
     * §3.4's rule, and the one that keeps a signed certificate true.
     *
     * A supplier invoice dated into a closed month lands in the **open** period with `incurred_on` preserved and
     * `is_late_for_period` set. The closed month's total stays exactly what the certificate was built on, and the
     * Late Costs report is what shows the arrival.
     */
    public function test_a_late_cost_lands_in_the_open_period_with_its_date_preserved(): void
    {
        $this->record($this->labour, 100_000, ['incurred_on' => '2026-08-10']);
        $august = CostPeriod::query()->starting('2026-08-01')->firstOrFail();
        $this->ledger->closePeriod($august);

        // September is open.
        CostPeriod::forDate('2026-09-01');

        $late = $this->record($this->material, 40_000, ['incurred_on' => '2026-08-28']);

        $this->assertSame('2026-08-28', $late->incurred_on->toDateString(), 'the real date must survive');
        $this->assertSame('2026-09-01', $late->posting_period->toDateString(), 'it must land in the open period');
        $this->assertTrue($late->is_late_for_period);

        // And August still says what it said when it was signed off.
        $this->assertSame(100_000.0, $this->ledger->totalFor($this->job, '2026-08-01'));
    }

    public function test_closing_a_period_records_what_the_ledger_said(): void
    {
        $this->record($this->labour, 100_000, ['incurred_on' => '2026-08-10']);
        $this->record($this->material, 60_000, ['incurred_on' => '2026-08-11']);

        $period = $this->ledger->closePeriod(CostPeriod::query()->firstOrFail());

        $this->assertSame(CostPeriod::STATUS_CLOSED, $period->status);
        $this->assertSame('160000.00', $period->jc_control_total);
        $this->assertNotNull($period->closed_at);
    }

    public function test_a_period_cannot_be_closed_twice(): void
    {
        $this->record($this->labour, 100_000, ['incurred_on' => '2026-08-10']);
        $period = CostPeriod::query()->firstOrFail();
        $this->ledger->closePeriod($period);

        $this->expectExceptionMessageMatches('/already closed/');

        $this->ledger->closePeriod($period->fresh());
    }

    // ---------------------------------------------------------------- amending

    /** §3.3: an edit in an open period is allowed, because a reversal pair for a ten-second-old typo is noise. */
    public function test_an_entry_in_an_open_period_may_be_amended(): void
    {
        $entry = $this->record($this->labour, 100_000);

        $amended = $this->ledger->amend($entry, ['amount' => 120_000, 'description' => 'Corrected']);

        $this->assertSame('120000.00', $amended->amount);
        $this->assertSame(1, CostEntry::query()->count(), 'an open-period typo should not leave three rows');
    }

    public function test_an_entry_in_a_closed_period_refuses_to_be_amended(): void
    {
        $entry = $this->record($this->labour, 100_000, ['incurred_on' => '2026-08-10']);
        $this->ledger->closePeriod(CostPeriod::query()->firstOrFail());

        $this->expectExceptionMessageMatches('/closed period. Reverse it/');

        $this->ledger->amend($entry->fresh(), ['amount' => 1]);
    }

    public function test_an_entry_that_reached_the_general_ledger_refuses_to_be_amended(): void
    {
        $entry = $this->record($this->labour, 100_000);
        $entry->update(['posted_to_gl_at' => now(), 'gl_treatment' => CostEntry::GL_POSTED]);

        $this->expectExceptionMessageMatches('/reached the general ledger/');

        $this->ledger->amend($entry->fresh(), ['amount' => 1]);
    }

    /** An amend cannot smuggle cost into a closed month by choosing its own period. */
    public function test_an_amend_cannot_choose_its_own_period(): void
    {
        $this->record($this->labour, 1, ['incurred_on' => '2026-08-10']);
        $this->ledger->closePeriod(CostPeriod::query()->starting('2026-08-01')->firstOrFail());
        CostPeriod::forDate('2026-09-01');

        $entry = $this->record($this->material, 50_000, ['incurred_on' => '2026-09-05']);

        $amended = $this->ledger->amend($entry, ['posting_period' => '2026-08-01', 'amount' => 60_000]);

        $this->assertSame('2026-09-01', $amended->posting_period->toDateString(), 'cost reached a closed month');
    }

    // ---------------------------------------------------------------- batches

    /**
     * A batch gives a bulk operation one thing to reverse.
     *
     * §3.2: "a reversal that has to re-find its two hundred rows by predicate is a reversal that will one day
     * find a hundred and ninety-nine, and nothing will say which one it missed." The batch pair summing to zero
     * is the cheapest possible proof it was complete.
     */
    public function test_reversing_a_batch_backs_out_every_entry_in_it(): void
    {
        $batch = CostBatch::create([
            'kind' => CostBatch::KIND_ALLOCATION,
            'period_start' => '2026-08-01',
            'description' => 'August overhead allocation',
        ]);

        foreach ([10_000, 20_000, 30_000] as $amount) {
            $this->record($this->labour, $amount, ['batch_id' => $batch->getKey()]);
        }

        $this->assertSame(60_000.0, $batch->total());

        $reversal = $this->ledger->reverseBatch($batch, 'Wrong basis');

        $this->assertSame(-60_000.0, $reversal->total());
        $this->assertSame(0.0, $this->ledger->totalFor($this->job), 'the allocation was not fully backed out');
        $this->assertTrue($batch->fresh()->isReversed());
    }

    public function test_a_batch_cannot_be_reversed_twice(): void
    {
        $batch = CostBatch::create(['period_start' => '2026-08-01']);
        $this->record($this->labour, 10_000, ['batch_id' => $batch->getKey()]);
        $this->ledger->reverseBatch($batch);

        $this->expectExceptionMessageMatches('/already been reversed/');

        $this->ledger->reverseBatch($batch->fresh());
    }

    // ---------------------------------------------------------------- the report

    public function test_the_report_is_one_row_per_cost_code_with_a_unit_rate(): void
    {
        $this->record($this->labour, 250_000, ['quantity' => 12.5]);
        $this->record($this->labour, 50_000, ['quantity' => 2.5]);
        $this->record($this->material, 180_000, ['quantity' => 90]);

        $report = $this->ledger->reportFor($this->job);

        $this->assertCount(2, $report);

        $steel = collect($report)->firstWhere('code', '02.100');
        $this->assertSame(300_000.0, $steel['amount']);
        $this->assertSame(15.0, $steel['quantity']);
        // The unit rate is the whole of cost control, and unavailable from money alone — §3.1's first reason.
        $this->assertSame(20_000.0, $steel['unit_rate']);
    }

    /** Nothing measured means no rate, not a division by zero. */
    public function test_a_code_with_no_quantity_has_no_unit_rate(): void
    {
        $this->record($this->labour, 100_000);

        $this->assertNull(collect($this->ledger->reportFor($this->job))->firstWhere('code', '02.100')['unit_rate']);
    }

    /**
     * The report rolls up the job tree.
     *
     * §1.2's whole reason for the hierarchy: the board asks for a development's consolidated cost while the
     * per-lot certificate needs the lot alone, and both come off the same query with a different root.
     */
    public function test_the_report_rolls_up_sub_jobs(): void
    {
        $tower = Job::create(['code' => 'J-1-A', 'name' => 'Tower A', 'parent_id' => $this->job->getKey()]);

        $this->record($this->labour, 100_000);
        $this->ledger->record($tower, $this->labour, ['amount' => 40_000, 'incurred_on' => '2026-08-10']);

        $this->assertSame(140_000.0, $this->ledger->totalFor($this->job->fresh()), 'the parent must include its tower');
        $this->assertSame(40_000.0, $this->ledger->totalFor($tower->fresh()), 'the tower alone is what its certificate needs');
    }

    public function test_the_report_can_be_asked_for_one_period(): void
    {
        $this->record($this->labour, 100_000, ['incurred_on' => '2026-08-10']);
        $this->ledger->closePeriod(CostPeriod::query()->firstOrFail());
        CostPeriod::forDate('2026-09-01');
        $this->record($this->labour, 25_000, ['incurred_on' => '2026-09-05']);

        $this->assertSame(100_000.0, $this->ledger->totalFor($this->job, '2026-08-01'));
        $this->assertSame(25_000.0, $this->ledger->totalFor($this->job, '2026-09-01'));
        $this->assertSame(125_000.0, $this->ledger->totalFor($this->job), 'and to date is both');
    }

    /** A WBS node may carry the cost, which is what makes the control account of §2 computable. */
    public function test_cost_may_be_filed_against_a_wbs_node(): void
    {
        $node = WbsNode::create(['job_id' => $this->job->getKey(), 'code' => '1.1', 'name' => 'Piling']);

        $entry = $this->record($this->labour, 100_000, ['wbs_node_id' => $node->getKey()]);

        $this->assertSame($node->getKey(), $entry->wbs_node_id);
        $this->assertSame('Piling', $entry->wbsNode->name);
    }

    /** Deleting a job takes its cost with it; a code that has cost against it cannot be deleted. */
    public function test_a_cost_code_with_cost_against_it_is_protected(): void
    {
        $this->record($this->labour, 100_000);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->labour->delete();
    }

    // ---------------------------------------------------------------- the screens

    /**
     * The register renders, and creating through the screen goes through `CostLedger`.
     *
     * The page could have written the row itself, and that would have been a second and weaker path into the
     * ledger — one that skipped the derived period, the snapshotted cost type and the heading refusal. This
     * asserts the screen uses the service by checking a property only the service sets.
     */
    public function test_the_register_renders(): void
    {
        $this->record($this->labour, 100_000);

        \Livewire\Livewire::test(\App\Modules\ConstructionCosting\Filament\Resources\CostEntries\CostEntryResource::getPages()['index']->getPage())
            ->assertSuccessful()
            ->assertSee('02.100');
    }

    public function test_creating_through_the_screen_derives_the_period_from_the_service(): void
    {
        \Livewire\Livewire::test(\App\Modules\ConstructionCosting\Filament\Resources\CostEntries\CostEntryResource::getPages()['create']->getPage())
            ->fillForm([
                'job_id' => $this->job->getKey(),
                'cost_code_id' => $this->labour->getKey(),
                'amount' => 75_000,
                'incurred_on' => '2026-08-14',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = CostEntry::query()->latest('id')->firstOrFail();

        $this->assertSame('2026-08-01', $entry->posting_period->toDateString());
        // Snapshotted by the service, never chosen on the form.
        $this->assertSame(CostCode::TYPE_LABOUR, $entry->cost_type);
    }

    /** The live report renders and shows what the ledger says. */
    public function test_the_cost_report_renders_the_live_figures(): void
    {
        $this->record($this->labour, 250_000, ['quantity' => 12.5]);
        $this->record($this->material, 180_000, ['quantity' => 90]);

        \Livewire\Livewire::test(\App\Modules\ConstructionCosting\Filament\Pages\JobCostReport::class)
            ->assertSuccessful()
            ->assertSee('02.100')
            ->assertSee('03.100')
            ->assertSee('430,000.00');
    }

    /**
     * An empty report says which kind of empty it is.
     *
     * "No cost recorded" and "nothing spent" look identical as a blank table, and a contractor reading the second
     * when it is the first makes a decision on a figure that does not exist.
     */
    public function test_an_empty_report_says_no_cost_has_been_recorded(): void
    {
        \Livewire\Livewire::test(\App\Modules\ConstructionCosting\Filament\Pages\JobCostReport::class)
            ->assertSuccessful()
            ->assertSee('No cost has been recorded');
    }
}
