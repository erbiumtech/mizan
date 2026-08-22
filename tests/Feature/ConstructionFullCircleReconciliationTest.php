<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostBatch;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Services\AccrualService;
use App\Modules\ConstructionCosting\Services\ConstructionGlPostingService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use App\Modules\ConstructionCosting\Services\InvoiceAllocationService;
use App\Modules\ConstructionCosting\Services\LabourRateService;
use App\Modules\ConstructionCosting\Services\LabourRecordService;
use App\Modules\ConstructionCosting\Services\PeriodCloseService;
use App\Modules\ConstructionCosting\Services\PlantService;
use App\Modules\ConstructionCosting\Services\ReconciliationService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * §4.3's fifth mechanism — `docs/construction-management-plan.md` §4.3, Phase 11f.
 *
 * > "5. A feature test that posts one of every source type — allocated supplier invoice, GRN accrual, material issue,
 * > labour with burden, internal plant, subcontract certificate with retention, overhead allocation — and asserts
 * > `difference === 0.00`. **That is what turns this section from a claim into an assertion.**"
 *
 * This is that test, and it is the one Phase 11 exists for. Every other test in the suite proves one rule in isolation.
 * This one drives the **real services** — `InvoiceAllocationService`, `GoodsReceiptService`, `LabourRecordService`,
 * `PlantService`, `AccrualService`, `ConstructionGlPostingService`, `ReconciliationService`, `PeriodCloseService` — over
 * one month of one job, and asks the only question §4 cares about.
 *
 * **Nothing here is a shortcut, and that is the point.** A full-circle test that wrote its cost entries directly would
 * prove the reconciliation's arithmetic and nothing about the seven writers that feed it — which is precisely the
 * failure §4.3 predicts: "a reconciliation written at the end against eight source types that were built without it in
 * mind is a reconciliation that will not balance, and nobody will know which of the eight is wrong."
 *
 * **On the subcontract certificate.** §4.3's list names it, and `construction_contracts` writes no cost entry — a
 * payable certificate is a *document*, and the cost reaches the job as §4.5's subcontract accrual until the
 * subcontractor's invoice arrives and is allocated. So the source type is exercised as the accrual, driven from a real
 * claim and a real certificate with retention on it, which is where the money actually is.
 */
class ConstructionFullCircleReconciliationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $labourCode;

    private CostCode $materialCode;

    private CostCode $plantCode;

    private CostCode $subcontractCode;

    private CostCode $overheadCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'fullcircle@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'construction_contracts', 'accounting', 'invoicing'] as $module) {
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
        ]);

        $this->labourCode = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);
        $this->materialCode = CostCode::create([
            'code' => '03.100', 'name' => 'Ready-mix concrete', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 'm3',
        ]);
        $this->plantCode = CostCode::create([
            'code' => '05.100', 'name' => 'Excavation plant', 'cost_type' => CostCode::TYPE_PLANT, 'unit' => 'hr',
        ]);
        $this->subcontractCode = CostCode::create([
            'code' => '07.100', 'name' => 'Cladding', 'cost_type' => CostCode::TYPE_SUBCONTRACT, 'unit' => 'm2',
        ]);
        $this->overheadCode = CostCode::create([
            'code' => '01.900', 'name' => 'Site overhead', 'cost_type' => CostCode::TYPE_OTHER, 'unit' => 'item',
        ]);

        $this->nominateEveryControlAccount();
    }

    /**
     * The chart of accounts §4 needs, nominated in full.
     *
     * Every posting rule gets an account, because the whole question this test asks is whether the two ledgers agree —
     * and a missing absorption account would leave cost pending, which balances (§4.2 counts pending as a reconciling
     * item) and proves nothing about whether the posting works.
     */
    private function nominateEveryControlAccount(): void
    {
        $cost = [
            'labour' => ['5110', 'Job cost — labour'],
            'material' => ['5120', 'Job cost — material'],
            'plant' => ['5130', 'Job cost — plant'],
            'subcontract' => ['5140', 'Job cost — subcontract'],
            'other' => ['5190', 'Job cost — other'],
        ];

        foreach ($cost as $type => [$code, $name]) {
            ControlAccount::create([
                'account_id' => $this->account($code, $name, 'expense')->getKey(),
                'kind' => ControlAccount::KIND_COST,
                'cost_type' => $type,
            ]);
        }

        $rules = [
            'labour_burden' => ['5910', 'Labour burden absorbed', ControlAccount::KIND_RECOVERY],
            'plant_internal_hire' => ['5920', 'Plant internal hire recovery', ControlAccount::KIND_RECOVERY],
            'site_labour' => ['2410', 'Site wages payable', ControlAccount::KIND_ACCRUAL],
            'grni' => ['2420', 'Goods received not invoiced', ControlAccount::KIND_ACCRUAL],
            'subcontract_accrual' => ['2430', 'Accrued subcontract costs', ControlAccount::KIND_ACCRUAL],
            'overhead_allocation' => ['5930', 'Overhead absorbed', ControlAccount::KIND_RECOVERY],
        ];

        foreach ($rules as $purpose => [$code, $name, $kind]) {
            ControlAccount::create([
                // Every credit side is a liability here: an absorbed recovery and an accrual are both money the company
                // owes or has recovered against, and what §4 cares about is that the credit exists at all.
                'account_id' => $this->account($code, $name, 'liability')->getKey(),
                'kind' => $kind,
                'purpose' => $purpose,
            ]);
        }
    }

    private function account(string $code, string $name, string $type): Account
    {
        return Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
    }

    private function supplier(): Contact
    {
        return Contact::firstOrCreate(
            ['name' => 'Concrete Supplier Ltd'],
            ['kind' => Contact::KIND_SUPPLIER],
        );
    }

    // ------------------------------------------------------ the seven source types

    /**
     * **One.** An allocated supplier invoice — §4.1's first row, mirrored.
     *
     * The invoice reaches the general ledger through Invoicing. The job-cost entry is the same money seen from the job's
     * side, and posting it again would state the company's material cost twice.
     */
    private function allocatedSupplierInvoice(float $amount): void
    {
        $costAccount = $this->account('5120', 'Job cost — material', 'expense');
        $payable = $this->account('2400', 'Accounts payable', 'liability');

        $journal = app(JournalEntryService::class)->create([
            'entry_date' => '2026-08-18',
            'entry_type' => 'general',
            'memo' => 'Purchase invoice PINV-1',
        ], [
            ['account_id' => $costAccount->getKey(), 'debit_amount' => $amount, 'description' => 'Concrete'],
            ['account_id' => $payable->getKey(), 'credit_amount' => $amount, 'description' => 'Concrete'],
        ]);
        $journal->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        app(JournalEntryService::class)->post($journal);

        $invoice = Invoice::create([
            'invoice_number' => 'PINV-1',
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $this->supplier()->getKey(),
            'invoice_date' => '2026-08-18',
            'status' => 'issued',
            'subtotal' => $amount,
            'total' => $amount,
            'journal_entry_id' => $journal->getKey(),
        ]);

        app(InvoiceAllocationService::class)->allocate($invoice, $this->job, $this->materialCode, $amount, [
            'description' => 'Concrete grade 30',
        ]);
    }

    /**
     * **Two.** A goods receipt with no invoice behind it yet — §4.5's first accrual, raised at receipt.
     *
     * `pending`, because nothing has posted a general-ledger side. §11a posts it as Dr job cost / Cr GRNI.
     */
    private function goodsReceivedNotInvoiced(float $amount): void
    {
        $commitmentId = DB::table('construction_commitments')->insertGetId([
            'number' => 'PO-0001',
            'type' => 'purchase_order',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineId = DB::table('construction_commitment_lines')->insertGetId([
            'commitment_id' => $commitmentId,
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $this->materialCode->getKey(),
            'description' => 'Rebar',
            'quantity' => 20,
            'unit_of_measure' => 't',
            // `rate` on a commitment line, `unit_rate` on a cost entry: §5's line is an order at a rate and §3.2's entry
            // is a cost at one, and the two tables name it differently.
            'rate' => $amount / 20,
            'amount' => $amount,
            'cost_type' => 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receipt = GoodsReceipt::create([
            'commitment_id' => $commitmentId,
            'number' => 'GRN-0001',
            'received_on' => '2026-08-20',
        ]);

        $receipt->lines()->create([
            'commitment_line_id' => $lineId,
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $this->materialCode->getKey(),
            'description' => 'Rebar',
            'quantity' => 20,
            'unit_of_measure' => 't',
            'unit_rate' => $amount / 20,
            'amount' => $amount,
        ]);

        app(GoodsReceiptService::class)->post($receipt);
    }

    /**
     * **Three.** A material issue, which §6 makes a *reclassification* rather than a cost.
     *
     * The receipt is what costs the material; an issue moves it from the code it arrived on to the code it was used on,
     * as entries summing to zero. Charging on issue as well would charge every stocked delivery twice.
     *
     * Written directly rather than through `MaterialIssueService`, which needs Inventory, a stock location and FIFO
     * lots — three modules of fixture for a pair of rows whose only property this test cares about is that they cancel.
     * §6's own rule is asserted by `ConstructionMaterialIssueTest`; what matters here is that a `memo` pair is invisible
     * to the reconciliation, which is what the assertions below check.
     */
    private function materialIssueReclass(float $amount): void
    {
        $ledger = app(CostLedger::class);

        $ledger->record($this->job, $this->materialCode, [
            'kind' => CostEntry::KIND_RECLASS,
            'gl_treatment' => CostEntry::GL_MEMO,
            'amount' => -$amount,
            'incurred_on' => '2026-08-22',
            'description' => 'Issued out of the store',
        ]);
        $ledger->record($this->job, $this->labourCode, [
            'kind' => CostEntry::KIND_RECLASS,
            'gl_treatment' => CostEntry::GL_MEMO,
            'amount' => $amount,
            'incurred_on' => '2026-08-22',
            'description' => 'Issued to steel fixing',
        ]);
    }

    /**
     * **Four.** Labour with burden — §7.3's two entries, both `pending`.
     *
     * The worker is `direct` — not on the payroll — so §4.1's table puts the posting on construction: nobody else has a
     * general-ledger document for a gang paid on Friday. The burden is a second entry against the same code, flagged,
     * and it credits Labour Burden Absorbed.
     *
     * @return array{labour: float, burden: float}
     */
    private function labourWithBurden(): array
    {
        $trade = Trade::create(['code' => 'STF', 'name' => 'Steel fixer']);
        $worker = Worker::create([
            'code' => 'W-001',
            'name' => 'Karim',
            'engagement' => Worker::ENGAGEMENT_DIRECT,
            'trade_id' => $trade->getKey(),
        ]);

        app(LabourRateService::class)->set([], 600, '2026-01-01', [
            'overtime_multiplier' => 1.5,
            'burden_percent' => 20,
        ]);

        $sheet = app(LabourRecordService::class)->record($worker, $this->job, $this->labourCode, [
            'worked_on' => '2026-08-10',
            'normal_minutes' => 480,
        ]);

        $approved = app(LabourRecordService::class)->approve($sheet);

        return [
            'labour' => (float) $approved->labour_amount,
            'burden' => (float) $approved->burden_amount,
        ];
    }

    /**
     * **Five.** Internal plant hire on an owned machine — §7.3, `pending`.
     *
     * An owned excavator has no supplier invoice, so its log *is* the cost: a debit to the job and a credit to Plant
     * Internal Hire Recovery, which is what its depreciation, fuel and repairs accumulate against.
     */
    private function internalPlantHire(): float
    {
        $item = PlantItem::create([
            'code' => 'EXC-04',
            'name' => '20t tracked excavator',
            'ownership' => PlantItem::OWNERSHIP_OWNED,
            'working_rate' => 2_000,
            'idle_rate' => 800,
        ]);

        $log = app(PlantService::class)->log($item, $this->job, $this->plantCode, [
            'logged_on' => '2026-08-10',
            'working_units' => 8,
        ]);

        return (float) app(PlantService::class)->approve($log)->charge_amount;
    }

    /**
     * **Six.** A subcontract certificate with retention, and the accrual behind it — §4.5's second accrual.
     *
     * A payable certificate is a document: `construction_contracts` writes no cost entry, and the cost reaches the job
     * as work-done-not-certified until the subcontractor invoices for it. So the claim is live, the certificate covers
     * part of it with retention held, and `AccrualService` accrues the uncertified balance.
     *
     * @return float the accrued balance
     */
    private function subcontractCertificateWithRetention(float $claimed, float $certified, float $retentionPercent): float
    {
        $contractId = DB::table('construction_contracts')->insertGetId([
            'job_id' => $this->job->getKey(),
            'side' => 'payable',
            'contract_number' => 'SC-014-03',
            'title' => 'Cladding subcontract',
            'contract_sum' => 5_000_000,
            'retention_percent' => $retentionPercent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('construction_contract_items')->insertGetId([
            'contract_id' => $contractId,
            'item_no' => '1.1',
            'description' => 'Supply and fix cladding',
            'cost_code_id' => $this->subcontractCode->getKey(),
            'scheduled_value' => 5_000_000,
            'retention_applies' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claimId = DB::table('construction_progress_claims')->insertGetId([
            'contract_id' => $contractId,
            'claim_number' => 'STMT-1',
            'period_end' => '2026-08-31',
            'status' => 'submitted',
            'claimed_gross_to_date' => $claimed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('construction_progress_claim_lines')->insert([
            'progress_claim_id' => $claimId,
            'contract_item_id' => $itemId,
            'cumulative_work_value' => $claimed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certificateId = DB::table('construction_payment_certificates')->insertGetId([
            'contract_id' => $contractId,
            'certificate_number' => 'IPC-1',
            'sequence' => 1,
            'period_end' => '2026-08-31',
            'status' => 'issued',
            'gross_value_to_date' => $certified,
            // Retention held, which is what makes this the source type §4.3 names rather than a plain certificate.
            'retention_to_date' => round($certified * $retentionPercent / 100, 2),
            'current_due' => round($certified * (100 - $retentionPercent) / 100, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('construction_certificate_lines')->insert([
            'payment_certificate_id' => $certificateId,
            'contract_item_id' => $itemId,
            'item_no' => '1.1',
            'description' => 'Supply and fix cladding',
            'cumulative_work_value' => $certified,
            'line_retention' => round($certified * $retentionPercent / 100, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return round($claimed - $certified, 2);
    }

    /**
     * **Seven.** An overhead allocation — §4.1's construction-only cost, `pending`.
     *
     * A batch, because §3.2's argument applies: a month's allocation across two hundred codes needs one thing to
     * reverse. It credits Overhead Absorbed, which is what makes it a recovery rather than a second charge.
     */
    private function overheadAllocation(float $amount): void
    {
        $batch = CostBatch::create([
            'kind' => CostBatch::KIND_ALLOCATION,
            'period_start' => '2026-08-01',
            'description' => 'Site overhead allocation — August 2026',
        ]);

        app(CostLedger::class)->record($this->job, $this->overheadCode, [
            'kind' => CostEntry::KIND_ALLOCATION,
            'gl_purpose' => 'overhead_allocation',
            'amount' => $amount,
            'incurred_on' => '2026-08-31',
            'batch_id' => $batch->getKey(),
            'description' => 'Site overhead at 4% of direct cost',
        ]);
    }

    // ---------------------------------------------------------------- the assertion

    /**
     * **§4.3's fifth mechanism. This is the test the whole of Phase 11 exists to make pass.**
     *
     * One of every source type, through the real services, then post, then reconcile. `difference === 0.00`.
     */
    public function test_one_of_every_source_type_reconciles_to_nothing(): void
    {
        $this->allocatedSupplierInvoice(1_200_000);
        $this->goodsReceivedNotInvoiced(800_000);
        $this->materialIssueReclass(300_000);
        $labour = $this->labourWithBurden();
        $plant = $this->internalPlantHire();
        $accrued = $this->subcontractCertificateWithRetention(2_000_000, 1_500_000, 10);
        $this->overheadAllocation(180_000);

        // §4.5's accrual pass raises the subcontract balance. Run on the month itself, which is what an open does.
        $run = app(AccrualService::class)->open('2026-08-01');
        $this->assertSame($accrued, $run->subcontract->total, 'the uncertified 500,000 was accrued');

        // §4.1's posting takes everything construction owes into the books.
        $posting = app(ConstructionGlPostingService::class)->post('2026-08-01');
        $this->assertTrue($posting->journalEntry->is_posted);

        $statement = app(ReconciliationService::class)->compute('2026-08-01');

        $this->assertSame(
            0.0,
            $statement->difference,
            'One of every source type, and the two ledgers agree. '.$statement->describe(),
        );
        $this->assertTrue($statement->isBalanced());

        // And every source type actually got there, so the assertion above is not about an empty month.
        $this->assertGreaterThanOrEqual(9, CostEntry::query()->count());
        $this->assertSame(
            0.0,
            round((float) CostEntry::query()->awaitingGl()->inPeriod('2026-08-01')->sum('amount'), 2),
            'nothing is left owing the general ledger',
        );
    }

    /**
     * Every treatment is represented, which is what makes the balancing figure above meaningful.
     *
     * A month of nothing but `mirrored` entries would balance trivially. §4.1's table has four rows and this month has
     * all four in it.
     */
    public function test_the_month_exercises_every_gl_treatment(): void
    {
        $this->allocatedSupplierInvoice(1_200_000);
        $this->goodsReceivedNotInvoiced(800_000);
        $this->materialIssueReclass(300_000);
        $this->labourWithBurden();
        $this->internalPlantHire();
        $this->overheadAllocation(180_000);

        $before = CostEntry::query()
            ->inPeriod('2026-08-01')
            ->selectRaw('gl_treatment, COUNT(*) as entries')
            ->groupBy('gl_treatment')
            ->pluck('entries', 'gl_treatment');

        $this->assertGreaterThan(0, $before[CostEntry::GL_MIRRORED] ?? 0, 'the supplier invoice');
        $this->assertGreaterThan(0, $before[CostEntry::GL_PENDING] ?? 0, 'labour, burden, plant, GRNI, overhead');
        $this->assertGreaterThan(0, $before[CostEntry::GL_MEMO] ?? 0, 'the material issue');

        app(ConstructionGlPostingService::class)->post('2026-08-01');

        $this->assertGreaterThan(
            0,
            CostEntry::query()->inPeriod('2026-08-01')->where('gl_treatment', CostEntry::GL_POSTED)->count(),
            'and the fourth treatment exists only after a posting run',
        );
    }

    /**
     * **Every rule that owes a credit got one**, which is §7.3's failure as a positive assertion.
     *
     * "Charge either and never absorb it and job cost exceeds GL cost by exactly the burden, growing every month, with
     * no error anywhere." Here every charge has a matching credit, and the journal names each.
     */
    public function test_every_charge_has_a_matching_credit_in_the_journal(): void
    {
        $this->goodsReceivedNotInvoiced(800_000);
        $this->labourWithBurden();
        $this->internalPlantHire();
        $this->overheadAllocation(180_000);

        $entry = app(ConstructionGlPostingService::class)->post('2026-08-01')->journalEntry;

        $this->assertTrue($entry->isBalanced());

        foreach (['Labour burden absorbed', 'Plant internal hire recovery', 'Site wages payable',
            'Goods received not invoiced', 'Overhead absorbed'] as $rule) {
            $this->assertTrue(
                $entry->lines->contains(fn ($line): bool => str_contains((string) $line->description, $rule)),
                "{$rule} has a line in the journal",
            );
        }
    }

    /**
     * **§4.1's batch link, on the real thing.**
     *
     * "A summary posting without that link is a number in the accounts nobody can explain, and should be treated as a
     * defect rather than a shortcut." Every entry that contributed explodes back out of the journal it reached.
     */
    public function test_the_posted_journal_explodes_back_into_its_entries(): void
    {
        $this->goodsReceivedNotInvoiced(800_000);
        $this->labourWithBurden();
        $this->internalPlantHire();
        $this->overheadAllocation(180_000);

        $posting = app(ConstructionGlPostingService::class)->post('2026-08-01');

        $this->assertSame($posting->entry_count, $posting->entries()->count());
        $this->assertSame(
            round((float) $posting->total_amount, 2),
            round((float) $posting->entries()->sum('amount'), 2),
        );
    }

    /**
     * **And the month closes.** The whole of §4.3 end to end: proved, then posted, then reconciled, then shut.
     *
     * A close that recorded a difference of nil is the only outcome that means anything here — and it is `reconciled`
     * rather than merely `closed`, because it was proved rather than signed for.
     */
    public function test_the_month_closes_reconciled(): void
    {
        $this->allocatedSupplierInvoice(1_200_000);
        $this->goodsReceivedNotInvoiced(800_000);
        $this->materialIssueReclass(300_000);
        $this->labourWithBurden();
        $this->internalPlantHire();
        $this->subcontractCertificateWithRetention(2_000_000, 1_500_000, 10);
        $this->overheadAllocation(180_000);

        app(AccrualService::class)->open('2026-08-01');
        app(ConstructionGlPostingService::class)->post('2026-08-01');
        app(ReconciliationService::class)->run('2026-08-01');

        $checklist = app(PeriodCloseService::class)->checks(CostPeriod::forDate('2026-08-01'));
        $this->assertTrue($checklist->canClose(), $checklist->describe());

        $closed = app(PeriodCloseService::class)->close(CostPeriod::forDate('2026-08-01'));

        $this->assertSame(CostPeriod::STATUS_RECONCILED, $closed->status);
        $this->assertSame('0.00', $closed->difference);
        $this->assertSame($closed->gl_control_total, $closed->jc_control_total);
    }

    /**
     * **The memo pair is invisible to both sides**, which is §6's rule seen from §4.
     *
     * A material issue reclassifies cost between codes and changes the job's total by nothing. If it reached the
     * reconciliation on either side, the difference would be the issue's value — and a company that issues from a store
     * every day would never balance.
     */
    public function test_a_material_issue_changes_neither_side_of_the_reconciliation(): void
    {
        $this->allocatedSupplierInvoice(1_200_000);
        $withoutIssue = app(ReconciliationService::class)->compute('2026-08-01');

        $this->materialIssueReclass(300_000);
        $withIssue = app(ReconciliationService::class)->compute('2026-08-01');

        $this->assertSame($withoutIssue->jobCost, $withIssue->jobCost);
        $this->assertSame($withoutIssue->difference, $withIssue->difference);
        $this->assertSame(0.0, $withIssue->difference);
    }

    /**
     * **The counter-test: break one link and the reconciliation says so, by name.**
     *
     * §4.3's whole argument for this test is that "a reconciliation written at the end against eight source types that
     * were built without it in mind is a reconciliation that will not balance, and nobody will know which of the eight
     * is wrong". A green full-circle test proves the first half. This proves the second: the report names which one.
     */
    public function test_removing_one_absorption_account_names_which_source_type_broke(): void
    {
        ControlAccount::query()->where('purpose', 'labour_burden')->delete();

        $this->labourWithBurden();
        $this->internalPlantHire();

        $plan = app(ConstructionGlPostingService::class)->plan('2026-08-01');
        $this->assertStringContainsString('Labour burden absorbed', $plan->describe());

        app(ConstructionGlPostingService::class)->post('2026-08-01');
        $statement = app(ReconciliationService::class)->compute('2026-08-01');

        // It still balances, because §4.2 counts pending cost as a reconciling item rather than a difference — and the
        // absorption gap is what says the month will never close.
        $this->assertTrue($statement->isBalanced());

        $gap = collect($statement->causes)->firstWhere('key', 'absorption_gaps');
        $this->assertSame(1, $gap->count);
        $this->assertSame('Labour burden absorbed', $gap->rows[0]['purpose']);

        $checklist = app(PeriodCloseService::class)->checks(CostPeriod::forDate('2026-08-01'));
        $this->assertFalse($checklist->canClose());
        $this->assertStringContainsString('Labour burden absorbed', $checklist->describe());
    }
}
