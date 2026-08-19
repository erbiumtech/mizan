<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages\ListLabourRecords;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\LabourRateService;
use App\Modules\ConstructionCosting\Services\LabourRecordService;
use App\Modules\Core\Models\CompanyModule;
use App\Support\ModuleMap;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Site sheets, the snapshot, and burden as its own entry — §7.1 and §7.3, Phase 7b.
 *
 * Five properties carry this file, and the first two are the ones §7 exists for.
 *
 *  - **Approval snapshots the rate, and nothing recomputes.** §7.2's dated table stops a wage revision restating
 *    history; this snapshot is what holds when somebody edits the rate row itself, or deletes it. Asserted by moving
 *    the rate afterwards and checking the booked cost does not move.
 *  - **The rate is the one that applied on the day worked**, never today's. A sheet for August approved in September
 *    is costed at August's rate, which is the entire reason the rate table is dated.
 *  - **Two entries, never one** (§7.3), against the same cost code, the burden one flagged. "Labour cost" stays one
 *    number and burden stays separable from one sum and a filter.
 *  - **Burden is `pending`, not `memo`.** The plan contradicted itself here and Phase 7b resolved it: §7.3 requires
 *    burden charged to jobs to credit Labour Burden Absorbed, so it has a GL side that §11 will post. A `memo` burden
 *    would drop out of §4.2's reconciliation and job cost would exceed GL cost by exactly the burden, for ever.
 *  - **No rate is a refusal.** Nothing here books an hour at zero, because a week of labour costing 0.00 looks like a
 *    healthy figure and is not.
 */
class ConstructionLabourRecordTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labourCode;

    private Trade $steelFixer;

    private Worker $karim;

    private LabourRecordService $sheets;

    private LabourRateService $rates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'sheets@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->labourCode = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);
        $this->steelFixer = Trade::create(['code' => 'STF', 'name' => 'Steel fixer']);
        $this->karim = Worker::create([
            'code' => 'W-001', 'name' => 'Karim', 'trade_id' => $this->steelFixer->getKey(),
        ]);

        $this->sheets = app(LabourRecordService::class);
        $this->rates = app(LabourRateService::class);
    }

    /** 600 an hour, time and a half, 20% burden — the fixture most tests start from. */
    private function companyRate(float $rate = 600, ?float $burden = 20, string $from = '2026-01-01'): void
    {
        $this->rates->set([], $rate, $from, ['overtime_multiplier' => 1.5, 'burden_percent' => $burden]);
    }

    /** @param array<string, mixed> $attributes */
    private function sheet(array $attributes = []): LabourRecord
    {
        return $this->sheets->record($this->karim, $this->job, $this->labourCode, array_merge([
            'worked_on' => '2026-08-10',
            'normal_minutes' => 480,
        ], $attributes));
    }

    // ------------------------------------------------------------------ recording

    /** A sheet starts as a draft, and a draft has no rate on it: the rate arrives at approval. */
    public function test_a_sheet_is_a_draft_with_no_rate_on_it(): void
    {
        $record = $this->sheet();

        $this->assertTrue($record->isDraft());
        $this->assertNull($record->cost_rate_per_hour);
        $this->assertNull($record->labour_amount);
        $this->assertNull($record->cost_entry_id);
        $this->assertSame(0, CostEntry::query()->count(), 'a draft costs the job nothing');
    }

    /** The trade defaults to the worker's own, so the ladder can be asked about the work. */
    public function test_the_trade_defaults_to_the_workers_own(): void
    {
        $this->assertSame($this->steelFixer->getKey(), $this->sheet()->trade_id);
    }

    /** And can be overridden, because a mason labouring for a day is costed as a labourer. */
    public function test_the_trade_can_be_the_one_worked_as(): void
    {
        $labourer = Trade::create(['code' => 'LAB', 'name' => 'Labourer']);

        $record = $this->sheet(['trade_id' => $labourer->getKey()]);

        $this->assertSame($labourer->getKey(), $record->trade_id);
    }

    /** Hours are a read over minutes, never the other way round. */
    public function test_hours_are_derived_from_minutes(): void
    {
        $record = $this->sheet(['normal_minutes' => 450, 'overtime_minutes' => 90]);

        $this->assertSame(540, $record->totalMinutes());
        $this->assertSame(9.0, $record->hours());
    }

    // ------------------------------------------------------------------ approval and the snapshot

    /**
     * **The exit condition of this sub-phase.** Approving books labour and burden as two entries.
     *
     * Eight hours at 600 is 4,800, and 20% burden on it is 960 — written as its own entry against the same cost code,
     * so "labour cost" is still 4,800 and burden is still 960.
     */
    public function test_approving_books_labour_and_burden_as_two_entries(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->assertTrue($record->isApproved());
        $this->assertEquals(4_800, $record->labour_amount);
        $this->assertEquals(960, $record->burden_amount);
        $this->assertSame(5_760.0, $record->totalCost());

        $this->assertSame(2, CostEntry::query()->count());

        $labour = $record->costEntry;
        $burden = $record->burdenEntry;

        $this->assertEquals(4_800, $labour->amount);
        $this->assertFalse($labour->is_burden);
        $this->assertEquals(960, $burden->amount);
        $this->assertTrue($burden->is_burden);

        // Same job, same code, so the two are one figure when nobody asks and two when somebody does.
        $this->assertSame($labour->cost_code_id, $burden->cost_code_id);
        $this->assertSame($labour->job_id, $burden->job_id);
    }

    /** The whole job cost is labour plus burden, and the labour half is answerable on its own. */
    public function test_labour_cost_stays_separable_from_burden(): void
    {
        $this->companyRate();
        $this->sheets->approve($this->sheet());

        $this->assertSame(5_760.0, app(CostLedger::class)->totalFor($this->job));
        $this->assertEquals(4_800, CostEntry::query()->where('is_burden', false)->sum('amount'));
        $this->assertEquals(960, CostEntry::query()->where('is_burden', true)->sum('amount'));
    }

    /** Overtime is paid at the multiplier, and the entry's unit rate is the blended one the day actually cost. */
    public function test_overtime_is_costed_at_the_multiplier(): void
    {
        $this->companyRate();

        $record = $this->sheets->approve($this->sheet(['normal_minutes' => 480, 'overtime_minutes' => 120]));

        // 4,800 + (2 × 600 × 1.5) = 6,600 over ten hours.
        $this->assertEquals(6_600, $record->labour_amount);
        $this->assertEquals(10, $record->costEntry->quantity);
        $this->assertSame('hr', $record->costEntry->unit_of_measure);
        $this->assertEquals(660, $record->costEntry->unit_rate, 'the blended rate, not the rate on the row');
    }

    /** The snapshot records which rate row it came from, so the figure can be traced rather than trusted. */
    public function test_the_snapshot_names_the_rate_row_it_came_from(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->assertNotNull($record->labour_rate_id);
        $this->assertEquals(600, $record->cost_rate_per_hour);
        $this->assertEquals(1.5, $record->overtime_multiplier);
        $this->assertEquals(20, $record->burden_percent);
        $this->assertEquals(600, $record->labourRate->cost_rate_per_hour);
    }

    /**
     * **A wage revision does not restate booked labour**, which is §7.2's whole purpose seen from this end.
     *
     * The dated table means April's rate never applied to March; the snapshot means even editing March's rate row
     * cannot reach cost already booked.
     */
    public function test_a_later_revision_does_not_restate_booked_cost(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet(['worked_on' => '2026-08-10']));

        $this->rates->revise([], 900, '2026-09-01');

        $this->assertEquals(600, $record->refresh()->cost_rate_per_hour);
        $this->assertEquals(4_800, $record->labour_amount);
        $this->assertEquals(4_800, $record->costEntry->amount);
    }

    /** And nor does editing the rate row the snapshot came from, which is the case the snapshot alone defends. */
    public function test_editing_the_rate_row_does_not_restate_booked_cost(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $record->labourRate->update(['cost_rate_per_hour' => 5]);

        $this->assertEquals(600, $record->refresh()->cost_rate_per_hour);
        $this->assertEquals(4_800, $record->costEntry->amount);
    }

    /**
     * The rate is the one in force **on the day worked**, not on the day approved.
     *
     * A sheet for August approved in September is costed at August's rate. Getting this backwards would restate a
     * month every time a revision landed between the work and the paperwork, which is most months.
     */
    public function test_the_rate_is_the_one_that_applied_on_the_day_worked(): void
    {
        $this->companyRate(600, 20, '2026-01-01');
        $this->rates->revise([], 900, '2026-09-01');

        $august = $this->sheets->approve($this->sheet(['worked_on' => '2026-08-10']));
        $september = $this->sheets->approve($this->sheet(['worked_on' => '2026-09-10']));

        $this->assertEquals(600, $august->cost_rate_per_hour);
        $this->assertEquals(900, $september->cost_rate_per_hour);
    }

    /** A rate set for the job beats the company default, through the ladder rather than through anything here. */
    public function test_the_ladder_decides_which_rate_applies(): void
    {
        $this->companyRate();
        $this->rates->set(['job_id' => $this->job->getKey()], 750, '2026-01-01');

        $record = $this->sheets->approve($this->sheet());

        $this->assertEquals(750, $record->cost_rate_per_hour);
        // The multiplier and burden still fall through to the company row, which states them.
        $this->assertEquals(1.5, $record->overtime_multiplier);
        $this->assertEquals(20, $record->burden_percent);
    }

    /** With no burden anywhere, no burden entry is written at all — rather than one for zero. */
    public function test_no_burden_means_no_burden_entry(): void
    {
        $this->companyRate(600, burden: null);

        $record = $this->sheets->approve($this->sheet());

        $this->assertEquals(0, $record->burden_amount);
        $this->assertNull($record->burden_entry_id);
        $this->assertSame(1, CostEntry::query()->count());
    }

    // ------------------------------------------------------------------ the general ledger side

    /**
     * **Burden is `pending`, not `memo`** — the contradiction Phase 7b resolved.
     *
     * §7.3 requires burden charged to a job to credit Labour Burden Absorbed, so it has a GL side and §11's posting
     * service is what writes it. `CostEntry::GL_MEMO`'s comment named burden as an example of "deliberately never
     * posts", which would have dropped it out of §4.2's `gl_treatment != 'memo'` sum and left job cost exceeding GL
     * cost by exactly the burden, growing every month, with nothing reporting an error.
     */
    public function test_burden_is_pending_rather_than_memo(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->assertSame(CostEntry::GL_PENDING, $record->burdenEntry->gl_treatment);
        $this->assertFalse($record->burdenEntry->isMemoOnly());
    }

    /** Labour for somebody outside the payroll is pending too: no GL document exists behind it. */
    public function test_labour_for_a_direct_hand_is_pending(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->assertSame(Worker::ENGAGEMENT_DIRECT, $record->worker->engagement);
        $this->assertSame(CostEntry::GL_PENDING, $record->costEntry->gl_treatment);
    }

    /**
     * An employee's time already reaches the ledger through the payslip, so job cost mirrors it.
     *
     * §4.1's table: "If the source is a GL document — supplier invoice, payment, stock movement, payslip —
     * construction posts nothing and mirrors." Posting it again would double the company's labour cost.
     */
    public function test_labour_for_an_employee_mirrors_the_payslip_when_payroll_is_licensed(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'employees'],
            ['licensed' => true, 'enabled' => true],
        );
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'payroll'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $employed = Worker::create([
            'code' => 'W-100', 'name' => 'Bilal',
            'engagement' => Worker::ENGAGEMENT_EMPLOYEE, 'employee_id' => 91,
            'trade_id' => $this->steelFixer->getKey(),
        ]);

        $this->companyRate();

        $record = $this->sheets->approve(
            $this->sheets->record($employed, $this->job, $this->labourCode, [
                'worked_on' => '2026-08-10', 'normal_minutes' => 480,
            ])
        );

        $this->assertSame(CostEntry::GL_MIRRORED, $record->costEntry->gl_treatment);
        // The burden is still construction's to post, whoever the person is.
        $this->assertSame(CostEntry::GL_PENDING, $record->burdenEntry->gl_treatment);
    }

    /**
     * Without Payroll, an employee's site labour is pending like anybody else's.
     *
     * "The payslip posted it" is only true where there are payslips. Claiming otherwise would leave a cost §4 could
     * never find a GL side for — §18.1's row for `payroll` says the gap is reported in words rather than balanced.
     */
    public function test_an_employees_labour_is_pending_without_payroll(): void
    {
        // Switched off explicitly: this tenant licenses payroll by default, so the absence has to be arranged rather
        // than assumed — the assertion below is what caught that.
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'payroll'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $employed = Worker::create([
            'code' => 'W-101', 'name' => 'Bilal',
            'engagement' => Worker::ENGAGEMENT_EMPLOYEE, 'employee_id' => 91,
            'trade_id' => $this->steelFixer->getKey(),
        ]);

        $this->companyRate();

        $record = $this->sheets->approve(
            $this->sheets->record($employed, $this->job, $this->labourCode, [
                'worked_on' => '2026-08-10', 'normal_minutes' => 480,
            ])
        );

        $this->assertFalse(modules()->enabled('payroll'));
        $this->assertSame(CostEntry::GL_PENDING, $record->costEntry->gl_treatment);
    }

    /** Both entries name the record that caused them, through the morph alias rather than the class name. */
    public function test_both_entries_name_the_sheet_that_caused_them(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        foreach ([$record->costEntry, $record->burdenEntry] as $entry) {
            $this->assertSame(ModuleMap::alias(LabourRecord::class), $entry->source_type);
            $this->assertSame($record->getKey(), (int) $entry->source_id);
            $this->assertSame($record->worker_id, $entry->worker_id);
        }
    }

    // ------------------------------------------------------------------ refusals

    /** No rate means refusal, which is the failure mode this whole section is arranged around. */
    public function test_approving_with_no_rate_is_refused(): void
    {
        $record = $this->sheet();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No labour rate applies');

        $this->sheets->approve($record);
    }

    /** And it books nothing on the way out. */
    public function test_a_refused_approval_books_no_cost(): void
    {
        $record = $this->sheet();

        try {
            $this->sheets->approve($record);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, CostEntry::query()->count());
        $this->assertTrue($record->refresh()->isDraft());
    }

    public function test_approving_twice_is_refused(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be approved again');

        $this->sheets->approve($record);
    }

    /** A day of no time books a cost of nothing, so it is refused at entry. */
    public function test_a_sheet_with_no_time_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no time tells nobody anything');

        $this->sheet(['normal_minutes' => 0, 'overtime_minutes' => 0]);
    }

    public function test_negative_time_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Negative time is not a correction');

        $this->sheet(['normal_minutes' => -60]);
    }

    /** A heading code would double-count in every rolled-up total, and the draft is refused rather than the approval. */
    public function test_a_heading_cost_code_is_refused_at_entry(): void
    {
        $parent = CostCode::create(['code' => '02', 'name' => 'Labour', 'cost_type' => CostCode::TYPE_LABOUR]);
        $this->labourCode->update(['parent_id' => $parent->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a heading');

        $this->sheets->record($this->karim, $this->job, $parent->refresh(), [
            'worked_on' => '2026-08-10', 'normal_minutes' => 480,
        ]);
    }

    /** Somebody who had already left cannot have worked, and the dates rather than the flag answer it. */
    public function test_a_day_outside_somebodys_engagement_is_refused(): void
    {
        $this->karim->update(['started_on' => '2026-01-01', 'ended_on' => '2026-06-30']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was not engaged on');

        $this->sheet(['worked_on' => '2026-08-10']);
    }

    /**
     * **The duplicate-sheet guard.** More than twenty-four hours in a day is the same sheet entered twice.
     *
     * A ceiling on the total rather than a uniqueness rule, deliberately: two records for one worker on one day are
     * ordinary — morning on formwork, afternoon on steel — so a unique index would refuse the normal case and catch
     * nothing.
     */
    public function test_two_sheets_in_one_day_are_fine_until_they_exceed_a_day(): void
    {
        $this->sheet(['normal_minutes' => 300]);
        $this->sheet(['normal_minutes' => 300]);

        $this->assertSame(2, LabourRecord::query()->count());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than a day');

        $this->sheet(['normal_minutes' => 900]);
    }

    /** A reversed sheet no longer counts towards the day, because its time is no longer booked. */
    public function test_a_reversed_sheet_frees_the_day_again(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet(['normal_minutes' => 1_400]));
        $this->sheets->reverse($record, 'Booked against the wrong job.');

        // Would have been refused before the reversal.
        $this->assertNotNull($this->sheet(['normal_minutes' => 480]));
    }

    // ------------------------------------------------------------------ reversal

    /** Reversing backs out both halves, as a pair. */
    public function test_reversing_backs_out_both_entries(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->sheets->reverse($record, 'The day was booked against the wrong job.');

        $this->assertTrue($record->refresh()->isReversed());
        $this->assertSame(0.0, app(CostLedger::class)->totalFor($this->job));

        // Four rows, not two: the originals stay, which is what explains the pair on the cost report.
        $this->assertSame(4, CostEntry::query()->count());
        $this->assertSame(2, CostEntry::query()->where('kind', CostEntry::KIND_REVERSAL)->count());
        $this->assertSame(1, CostEntry::query()->where('kind', CostEntry::KIND_REVERSAL)->where('is_burden', true)->count());
    }

    /** Reversing needs a reason, because the report will show both rows and nothing else explains them. */
    public function test_reversing_without_a_reason_is_refused(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->sheets->reverse($record, '   ');
    }

    public function test_a_draft_cannot_be_reversed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only approved labour has cost to reverse');

        $this->sheets->reverse($this->sheet(), 'Nothing to back out.');
    }

    // ------------------------------------------------------------------ editing a draft

    public function test_a_draft_can_be_corrected_in_place(): void
    {
        $record = $this->sheets->update($this->sheet(), ['normal_minutes' => 450]);

        $this->assertSame(450, $record->normal_minutes);
    }

    /** The day-length guard applies to an edit too, or eighty hours typed for eight would pass on the second save. */
    public function test_an_edit_cannot_push_the_day_over_a_day(): void
    {
        $record = $this->sheet(['normal_minutes' => 300]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than a day');

        $this->sheets->update($record, ['normal_minutes' => 4_800]);
    }

    public function test_an_approved_sheet_cannot_be_edited(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('its cost is booked');

        $this->sheets->update($record, ['normal_minutes' => 450]);
    }

    // ------------------------------------------------------------------ the screen

    /** The register renders, shows hours rather than minutes, and offers the approval queue. */
    public function test_the_register_shows_hours_and_the_queue(): void
    {
        $this->companyRate();
        $draft = $this->sheet();
        $approved = $this->sheets->approve($this->sheet(['worked_on' => '2026-08-11']));

        Livewire::test(ListLabourRecords::class)
            ->assertCanSeeTableRecords([$draft, $approved])
            ->assertSee('Draft')
            ->assertSee('Approved');
    }

    /** The approve action books the cost through the service. */
    public function test_the_approve_action_books_the_cost(): void
    {
        $this->companyRate();
        $record = $this->sheet();

        Livewire::test(ListLabourRecords::class)
            ->callAction(TestAction::make('approve')->table($record));

        $this->assertTrue($record->refresh()->isApproved());
        $this->assertSame(5_760.0, app(CostLedger::class)->totalFor($this->job));
    }

    /** And a refusal reaches the user as a notification rather than a stack trace. */
    public function test_the_approve_action_surfaces_a_missing_rate(): void
    {
        $record = $this->sheet();

        Livewire::test(ListLabourRecords::class)
            ->callAction(TestAction::make('approve')->table($record))
            ->assertNotified();

        $this->assertTrue($record->refresh()->isDraft());
        $this->assertSame(0, CostEntry::query()->count());
    }

    /** The reverse action takes both halves back off the job. */
    public function test_the_reverse_action_backs_the_day_out(): void
    {
        $this->companyRate();
        $record = $this->sheets->approve($this->sheet());

        Livewire::test(ListLabourRecords::class)
            ->callAction(TestAction::make('reverse')->table($record), ['reason' => 'Wrong job.']);

        $this->assertTrue($record->refresh()->isReversed());
        $this->assertSame(0.0, app(CostLedger::class)->totalFor($this->job));
    }
}
