<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Pages\ListPlantItems;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Pages\ListPlantLogs;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\InvoiceAllocationService;
use App\Modules\ConstructionCosting\Services\PlantHireMatch;
use App\Modules\ConstructionCosting\Services\PlantService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\ModuleMap;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Plant, internal hire recovery and the two-way match — §7.3, Phase 7c.
 *
 * **The decision this file is mostly about is which machines book cost.** §4.1 settles it: where a general-ledger
 * document already exists for a cost, construction mirrors or stays out of the way. An owned excavator has no invoice,
 * so its log *is* the cost — internal hire, charged to the job and credited to Plant Internal Hire Recovery by §11. A
 * hired one has a supplier invoice that reaches the job through §5's allocation chain, so its log books **nothing** and
 * becomes the check against that invoice instead. Get it wrong and the job is charged twice for one machine, with both
 * figures looking like plant cost on the same code.
 *
 * Four more properties:
 *
 *  - **A null rate means not charged**, never "fall back to the working rate", which would inflate every job that had
 *    a machine standing.
 *  - **A machine with no rate cannot be approved at all.** §18.1's healthy-looking figure hiding an absence, and worse
 *    on plant than on labour because nobody expects a machine to be free.
 *  - **The meter is evidence.** A mismatch with charged hours is ordinary; a reading that went backwards is refused.
 *  - **The match is cumulative**, because a hire invoice and the logs it covers are never dated in the same period.
 */
class ConstructionPlantTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $plantCode;

    private PlantService $plant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'plant@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->plantCode = CostCode::create([
            'code' => '04.100', 'name' => 'Excavation plant', 'cost_type' => CostCode::TYPE_PLANT, 'unit' => 'hr',
        ]);

        $this->plant = app(PlantService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function machine(array $attributes = []): PlantItem
    {
        return PlantItem::create(array_merge([
            'code' => 'EXC-04',
            'name' => '20t tracked excavator',
            'ownership' => PlantItem::OWNERSHIP_OWNED,
            'working_rate' => 2_000,
            'idle_rate' => 800,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function log(PlantItem $item, array $attributes = []): PlantLog
    {
        return $this->plant->log($item, $this->job, $this->plantCode, array_merge([
            'logged_on' => '2026-08-10',
            'working_units' => 8,
        ], $attributes));
    }

    // ------------------------------------------------------------------ the register

    /** A machine is owned unless somebody says otherwise, because most of a fleet is. */
    public function test_a_machine_is_owned_by_default(): void
    {
        $item = $this->machine();

        $this->assertTrue($item->isOwned());
        $this->assertFalse($item->isHired());
        $this->assertSame(PlantItem::METER_HOURS, $item->meter_unit);
    }

    /** Hired-with-operator is hired for every purpose that matters here. */
    public function test_hired_with_operator_is_hired(): void
    {
        $item = $this->machine(['code' => 'CRN-01', 'ownership' => PlantItem::OWNERSHIP_HIRED_WITH_OPERATOR]);

        $this->assertTrue($item->isHired());
        $this->assertFalse($item->isOwned());
    }

    /** A machine with no rate anywhere reports itself unchargeable, which is what stops an approval. */
    public function test_a_machine_with_no_rate_is_not_chargeable(): void
    {
        $this->assertTrue($this->machine()->hasChargeableRate());
        $this->assertFalse($this->machine([
            'code' => 'DMP-02', 'working_rate' => null, 'idle_rate' => null, 'standby_rate' => null,
        ])->hasChargeableRate());
    }

    // ------------------------------------------------------------------ logging

    public function test_a_log_starts_as_a_draft_with_no_charge(): void
    {
        $log = $this->log($this->machine());

        $this->assertTrue($log->isDraft());
        $this->assertNull($log->charge_amount);
        $this->assertNull($log->cost_entry_id);
        $this->assertSame(0, CostEntry::query()->count());
    }

    public function test_the_units_add_up_and_the_meter_is_a_read(): void
    {
        $log = $this->log($this->machine(), [
            'working_units' => 6, 'idle_units' => 2, 'meter_start' => 1_204.5, 'meter_end' => 1_211,
        ]);

        $this->assertSame(8.0, $log->totalUnits());
        $this->assertSame(6.5, $log->meterMovement());
    }

    /** No reading is not a problem; the movement is simply unavailable. */
    public function test_a_missing_meter_reading_reports_no_movement(): void
    {
        $this->assertNull($this->log($this->machine(), ['meter_start' => 1_000])->meterMovement());
    }

    // ------------------------------------------------------------------ owned plant books cost

    /**
     * **The exit condition for owned plant.** Approving charges the job internal hire.
     *
     * Eight working hours at 2,000 and two idle at 800 is 17,600 — one entry, `pending`, for §11 to credit to Plant
     * Internal Hire Recovery. "Debit the job with no credit and the fleet looks free while every job looks expensive."
     */
    public function test_approving_an_owned_machines_log_charges_internal_hire(): void
    {
        $log = $this->plant->approve($this->log($this->machine(), ['working_units' => 8, 'idle_units' => 2]));

        $this->assertTrue($log->isApproved());
        $this->assertEquals(17_600, $log->charge_amount);

        $entry = $log->costEntry;
        $this->assertNotNull($entry);
        $this->assertEquals(17_600, $entry->amount);
        $this->assertSame(CostEntry::GL_PENDING, $entry->gl_treatment);
        $this->assertSame(CostCode::TYPE_PLANT, $entry->cost_type);
        $this->assertSame(17_600.0, app(CostLedger::class)->totalFor($this->job));
    }

    /** The rates are snapshotted, so revising the machine's rate does not restate what is booked. */
    public function test_the_rates_are_snapshotted_onto_the_log(): void
    {
        $item = $this->machine();
        $log = $this->plant->approve($this->log($item));

        $item->update(['working_rate' => 9_999]);

        $this->assertEquals(2_000, $log->refresh()->working_rate);
        $this->assertEquals(16_000, $log->charge_amount);
        $this->assertEquals(16_000, $log->costEntry->amount);
    }

    /**
     * The unit rate reads as the rate actually applied, not the charge spread over every unit on site.
     *
     * A day of eight charged working hours and four uncharged idle ones is eight chargeable units; dividing by twelve
     * would report a rate two thirds of the one the company set, and §3.1's rate analysis is the point of the ledger.
     */
    public function test_the_unit_rate_counts_only_chargeable_units(): void
    {
        $item = $this->machine(['idle_rate' => null]);
        $log = $this->plant->approve($this->log($item, ['working_units' => 8, 'idle_units' => 4]));

        $this->assertEquals(16_000, $log->charge_amount, 'the idle hours are not charged');
        $this->assertEquals(8, $log->costEntry->quantity);
        $this->assertEquals(2_000, $log->costEntry->unit_rate);
    }

    /** A null rate means not charged — never a fall back to the working rate. */
    public function test_a_null_rate_charges_nothing_for_those_units(): void
    {
        $item = $this->machine(['idle_rate' => null, 'standby_rate' => null]);

        $log = $this->plant->approve($this->log($item, ['working_units' => 4, 'idle_units' => 4, 'standby_units' => 4]));

        $this->assertEquals(8_000, $log->charge_amount);
    }

    /** The entry names the log that caused it, through the morph alias. */
    public function test_the_entry_names_the_log_that_caused_it(): void
    {
        $log = $this->plant->approve($this->log($this->machine()));

        $this->assertSame(ModuleMap::alias(PlantLog::class), $log->costEntry->source_type);
        $this->assertSame($log->getKey(), (int) $log->costEntry->source_id);
    }

    /** An operator is carried onto the entry, so plant cost and the operator's own sheet can be tied together. */
    public function test_the_operator_is_carried_onto_the_entry(): void
    {
        $operator = \App\Modules\ConstructionCosting\Models\Worker::create(['code' => 'W-050', 'name' => 'Tariq']);

        $log = $this->plant->approve($this->log($this->machine(), ['operator_worker_id' => $operator->getKey()]));

        $this->assertSame($operator->getKey(), $log->costEntry->worker_id);
    }

    // ------------------------------------------------------------------ hired plant books nothing

    /**
     * **The other half of §7.3, and the one that prevents a double charge.**
     *
     * A hired machine's cost is its supplier invoice, which reaches the job through §5's allocation. Its log is priced
     * so the invoice can be checked against it — and books nothing.
     */
    public function test_approving_a_hired_machines_log_books_no_cost(): void
    {
        $item = $this->machine(['code' => 'CRN-01', 'ownership' => PlantItem::OWNERSHIP_HIRED]);

        $log = $this->plant->approve($this->log($item));

        $this->assertTrue($log->isApproved());
        $this->assertEquals(16_000, $log->charge_amount, 'still priced, because that is what checks the invoice');
        $this->assertNull($log->cost_entry_id);
        $this->assertSame(0, CostEntry::query()->count());
        $this->assertSame(0.0, app(CostLedger::class)->totalFor($this->job));
    }

    // ------------------------------------------------------------------ refusals

    public function test_approving_a_machine_with_no_rate_is_refused(): void
    {
        $item = $this->machine(['working_rate' => null, 'idle_rate' => null]);
        $log = $this->log($item);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no rate set');

        $this->plant->approve($log);
    }

    public function test_a_log_of_no_units_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no units tells nobody anything');

        $this->log($this->machine(), ['working_units' => 0]);
    }

    public function test_negative_units_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Negative plant units');

        $this->log($this->machine(), ['working_units' => -1]);
    }

    /** A meter cannot go backwards, and that is the only thing checked about it. */
    public function test_a_meter_that_went_backwards_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lower than the opening one');

        $this->log($this->machine(), ['meter_start' => 1_200, 'meter_end' => 1_100]);
    }

    /**
     * A meter disagreeing with the charged hours is **not** refused, and that is deliberate.
     *
     * Engine hours legitimately differ from charged hours. Refusing a mismatch would refuse the ordinary case and
     * teach everybody to leave the readings blank, which loses the evidence entirely.
     */
    public function test_a_meter_that_disagrees_with_the_hours_is_accepted(): void
    {
        $log = $this->log($this->machine(), [
            'working_units' => 8, 'meter_start' => 1_200, 'meter_end' => 1_209.75,
        ]);

        $this->assertSame(9.75, $log->meterMovement());
        $this->assertSame(8.0, $log->totalUnits());
    }

    public function test_a_heading_cost_code_is_refused(): void
    {
        $parent = CostCode::create(['code' => '04', 'name' => 'Plant', 'cost_type' => CostCode::TYPE_PLANT]);
        $this->plantCode->update(['parent_id' => $parent->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a heading');

        $this->plant->log($this->machine(), $this->job, $parent->refresh(), [
            'logged_on' => '2026-08-10', 'working_units' => 8,
        ]);
    }

    public function test_approving_twice_is_refused(): void
    {
        $log = $this->plant->approve($this->log($this->machine()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be approved again');

        $this->plant->approve($log);
    }

    // ------------------------------------------------------------------ reversal and editing

    public function test_reversing_an_owned_log_backs_the_cost_out(): void
    {
        $log = $this->plant->approve($this->log($this->machine()));

        $this->plant->reverse($log, 'Logged against the wrong job.');

        $this->assertTrue($log->refresh()->isReversed());
        $this->assertSame(0.0, app(CostLedger::class)->totalFor($this->job));
        $this->assertSame(2, CostEntry::query()->count(), 'the original stays, which is what explains the pair');
    }

    /** A hired log has no cost to back out, and reversing it is still meaningful: the check no longer stands. */
    public function test_reversing_a_hired_log_needs_no_cost_entry(): void
    {
        $item = $this->machine(['code' => 'CRN-01', 'ownership' => PlantItem::OWNERSHIP_HIRED]);
        $log = $this->plant->approve($this->log($item));

        $this->plant->reverse($log, 'The crane was not on site that day.');

        $this->assertTrue($log->refresh()->isReversed());
        $this->assertSame(0, CostEntry::query()->count());
    }

    public function test_reversing_without_a_reason_is_refused(): void
    {
        $log = $this->plant->approve($this->log($this->machine()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->plant->reverse($log, '  ');
    }

    public function test_a_draft_can_be_corrected_and_an_approved_log_cannot(): void
    {
        $log = $this->plant->update($this->log($this->machine()), ['working_units' => 6]);
        $this->assertEquals(6, $log->working_units);

        $approved = $this->plant->approve($this->log($this->machine(['code' => 'EXC-05'])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already priced');

        $this->plant->update($approved, ['working_units' => 6]);
    }

    /** The meter guard applies to an edit too, or a backwards reading passes on the second save. */
    public function test_an_edit_cannot_set_a_backwards_meter(): void
    {
        $log = $this->log($this->machine(), ['meter_start' => 1_200, 'meter_end' => 1_300]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lower than the opening one');

        $this->plant->update($log, ['meter_end' => 1_100]);
    }

    // ------------------------------------------------------------------ the two-way match (§7.3)

    /** Owned plant has no invoice to match, and the match says so rather than reporting a difference. */
    public function test_the_match_does_not_apply_to_owned_plant(): void
    {
        $match = app(PlantHireMatch::class)->for($this->machine());

        $this->assertSame(PlantHireMatch::STATUS_NOT_APPLICABLE, $match['status']);
        $this->assertStringContainsString('owned', $match['explanation']);
    }

    /** A hired machine with no order linked is the exposure, and the match names it. */
    public function test_a_hired_machine_with_no_order_has_nothing_to_match(): void
    {
        $item = $this->machine(['code' => 'CRN-01', 'ownership' => PlantItem::OWNERSHIP_HIRED]);

        $match = app(PlantHireMatch::class)->for($item);

        $this->assertSame(PlantHireMatch::STATUS_NOT_APPLICABLE, $match['status']);
        $this->assertStringContainsString('No hire order', $match['explanation']);
    }

    /**
     * **§7.3's two-way match, in the state it exists to catch: plant billed after it was collected.**
     *
     * Two days logged at 16,000 each, and an invoice for a month. The difference is what nobody would otherwise see.
     */
    public function test_the_match_reports_plant_invoiced_beyond_the_days_logged(): void
    {
        [$item, $line] = $this->hiredCraneOnOrder();

        $this->plant->approve($this->log($item, ['logged_on' => '2026-08-10']));
        $this->plant->approve($this->log($item, ['logged_on' => '2026-08-11']));

        $this->allocateHireInvoice($line, 80_000);

        $match = app(PlantHireMatch::class)->for($item->refresh());

        $this->assertSame(32_000.0, $match['expected']);
        $this->assertSame(80_000.0, $match['invoiced']);
        $this->assertSame(48_000.0, $match['difference']);
        $this->assertSame(PlantHireMatch::STATUS_OVER_BILLED, $match['status']);
        $this->assertStringContainsString('still on site', $match['explanation']);
    }

    /** Logged and not yet invoiced is the ordinary mid-month state, and reads as under-billed rather than as an error. */
    public function test_the_match_reports_hire_not_yet_invoiced(): void
    {
        [$item] = $this->hiredCraneOnOrder();

        $this->plant->approve($this->log($item));

        $match = app(PlantHireMatch::class)->for($item->refresh());

        $this->assertSame(PlantHireMatch::STATUS_UNDER_BILLED, $match['status']);
        $this->assertSame(-16_000.0, $match['difference']);
    }

    /** When they agree, they agree — within §5's own minimum-amount tolerance rather than a second one. */
    public function test_the_match_balances_when_the_figures_agree(): void
    {
        [$item, $line] = $this->hiredCraneOnOrder();

        $this->plant->approve($this->log($item));
        $this->allocateHireInvoice($line, 16_000);

        $match = app(PlantHireMatch::class)->for($item->refresh());

        $this->assertSame(PlantHireMatch::STATUS_BALANCED, $match['status']);
        $this->assertSame(0.0, $match['difference']);
    }

    /** An invoice with nothing logged at all is the sharpest version of the same finding. */
    public function test_an_invoice_with_nothing_logged_is_wholly_unsupported(): void
    {
        [$item, $line] = $this->hiredCraneOnOrder();

        $this->allocateHireInvoice($line, 40_000);

        $match = app(PlantHireMatch::class)->for($item->refresh());

        $this->assertSame(PlantHireMatch::STATUS_OVER_BILLED, $match['status']);
        $this->assertStringContainsString('Nothing has been logged', $match['explanation']);
    }

    /** A draft log is not evidence: it has not been agreed, so the match does not count it. */
    public function test_a_draft_log_does_not_support_an_invoice(): void
    {
        [$item, $line] = $this->hiredCraneOnOrder();

        $this->log($item);
        $this->allocateHireInvoice($line, 16_000);

        $match = app(PlantHireMatch::class)->for($item->refresh());

        $this->assertSame(0.0, $match['expected']);
        $this->assertSame(PlantHireMatch::STATUS_OVER_BILLED, $match['status']);
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_fleet_register_renders(): void
    {
        $owned = $this->machine();
        $hired = $this->machine(['code' => 'CRN-01', 'ownership' => PlantItem::OWNERSHIP_HIRED]);

        Livewire::test(ListPlantItems::class)
            ->assertCanSeeTableRecords([$owned, $hired])
            ->assertSee('Owned')
            ->assertSee('Hired');
    }

    /** The match action is offered on hired plant and hidden on owned, because owned has no invoice to check. */
    public function test_the_invoice_check_action_is_offered_only_on_hired_plant(): void
    {
        $owned = $this->machine();
        $hired = $this->machine(['code' => 'CRN-01', 'ownership' => PlantItem::OWNERSHIP_HIRED]);

        Livewire::test(ListPlantItems::class)
            ->assertActionHidden(TestAction::make('checkInvoices')->table($owned))
            ->assertActionVisible(TestAction::make('checkInvoices')->table($hired));
    }

    public function test_the_log_register_says_which_logs_book_cost(): void
    {
        $ownedLog = $this->log($this->machine());
        $hiredLog = $this->log($this->machine(['code' => 'CRN-01', 'ownership' => PlantItem::OWNERSHIP_HIRED]));

        Livewire::test(ListPlantLogs::class)
            ->assertCanSeeTableRecords([$ownedLog, $hiredLog])
            ->assertSee('Internal hire')
            ->assertSee('No — invoice does');
    }

    public function test_the_approve_action_prices_the_log(): void
    {
        $log = $this->log($this->machine());

        Livewire::test(ListPlantLogs::class)
            ->callAction(TestAction::make('approve')->table($log));

        $this->assertTrue($log->refresh()->isApproved());
        $this->assertSame(16_000.0, app(CostLedger::class)->totalFor($this->job));
    }

    /** And a machine with no rate surfaces as a notification rather than a stack trace. */
    public function test_the_approve_action_surfaces_a_missing_rate(): void
    {
        $log = $this->log($this->machine(['working_rate' => null, 'idle_rate' => null]));

        Livewire::test(ListPlantLogs::class)
            ->callAction(TestAction::make('approve')->table($log))
            ->assertNotified();

        $this->assertTrue($log->refresh()->isDraft());
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * A hired crane on an issued plant-hire order, which is what the two-way match needs on both sides.
     *
     * @return array{0: PlantItem, 1: \App\Modules\ConstructionCosting\Models\CommitmentLine}
     */
    private function hiredCraneOnOrder(): array
    {
        $commitments = app(CommitmentService::class);

        $order = $commitments->create([
            'type' => Commitment::TYPE_PLANT_HIRE,
            'contact_id' => Contact::firstOrCreate(['name' => 'Crane Hire Ltd'], ['type' => 'supplier'])->getKey(),
        ]);

        $commitments->addLine($order, $this->job, $this->plantCode, [
            'description' => '50t crane, monthly hire', 'quantity' => 1, 'rate' => 500_000,
        ]);

        $commitments->approve($order->refresh());
        $commitments->issue($order->refresh());

        $item = $this->machine([
            'code' => 'CRN-01',
            'name' => '50t mobile crane',
            'ownership' => PlantItem::OWNERSHIP_HIRED,
            'commitment_id' => $order->getKey(),
        ]);

        return [$item, $order->lines()->firstOrFail()];
    }

    /** A supplier invoice for the hire, allocated to the order line — §5's chain, which is how hired plant is costed. */
    private function allocateHireInvoice(\App\Modules\ConstructionCosting\Models\CommitmentLine $line, float $amount): void
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'status' => Invoice::STATUS_DRAFT,
            'contact_id' => Contact::firstOrCreate(['name' => 'Crane Hire Ltd'], ['type' => 'supplier'])->getKey(),
            'invoice_date' => '2026-08-31',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoiceLine = $invoice->lines()->create([
            'description' => 'Crane hire, August',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
        ]);

        app(InvoiceAllocationService::class)->allocate(
            $invoice->refresh(),
            $this->job,
            $this->plantCode,
            $amount,
            ['invoice_line_id' => $invoiceLine->getKey(), 'commitment_line_id' => $line->getKey()],
        );
    }
}
