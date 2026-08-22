<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostBatch;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Support\AccrualResult;
use App\Modules\ConstructionCosting\Support\AccrualRun;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The two accruals, and the reversal that keeps them honest — `docs/construction-management-plan.md` §4.5.
 *
 * §4.5 names two: **goods received not invoiced** (Dr job cost / Cr GRNI) and **subcontract work done not certified**
 * (Dr job cost / Cr accrued subcontract costs). Both are batched `kind = 'accrual'` entries, and both **auto-reverse at
 * the opening of the next period**.
 *
 * **Reverse-and-re-accrue rather than matching off**, and §4.5 gives the reason without hedging: "matching an accrual
 * line-by-line to a later invoice is the same heuristic that fails for commitment relief, and an accrual that fails to
 * match sits on the balance sheet forever with nobody able to say what it is for." Reverse-and-re-accrue is
 * self-correcting — whatever is still outstanding is re-raised from the current facts, and whatever has been invoiced
 * simply is not.
 *
 * **The reversal belongs to period *open*, not period close**, and that is §4.5's own conclusion about its own failure
 * mode: if the reversal does not run, "the accrual and the real invoice both sit in the ledger and the job costs double
 * for a month". A step attached to opening the month runs before anybody looks at the month's figures. A step attached
 * to closing it runs after everybody has.
 *
 * **Nothing here reaches `construction_contracts`.** The subcontract accrual reads
 * `construction_contracts`, `construction_progress_claims` and `construction_certificate_lines` with the query builder
 * rather than naming `Contract` or `PaymentCertificate` — and this is not stylistic. `construction_contracts` already
 * declares an edge to `construction_costing` (§6c's commitment path), so an import in the other direction would be a
 * two-cycle, and `ModuleBoundaryTest::test_the_module_graph_is_acyclic` would be right to fail it. A cycle cannot be
 * expressed as a composer dependency, which takes both modules out of the set that can be packaged.
 */
class AccrualService
{
    /**
     * Open a month: reverse what is outstanding, then re-accrue from today's facts.
     *
     * The order is load-bearing. Re-accruing first and reversing second would double every job's accrued cost for the
     * length of one transaction — which is fine inside a transaction and catastrophic if anything reads mid-flight, and
     * there is no reason to write it in the dangerous order.
     */
    public function open(string $periodStart): AccrualRun
    {
        $period = CostPeriod::forDate($periodStart);

        if ($period->isClosed()) {
            throw new InvalidArgumentException(
                "Cost period {$period->label()} is {$period->status}. Accruals roll into an open month — rolling them "
                .'into a closed one would restate a total somebody has already signed off.'
            );
        }

        $start = $period->period_start->toDateString();

        return TenantTransaction::run(function () use ($start): AccrualRun {
            $reversal = $this->reverseStandingAccruals($start);

            return new AccrualRun(
                periodStart: $start,
                reversalBatch: $reversal['batch'],
                reversedCount: $reversal['count'],
                reversedTotal: $reversal['total'],
                goodsReceived: $this->accrueGoodsReceivedNotInvoiced($start),
                subcontract: $this->accrueSubcontractWorkNotCertified($start),
            );
        });
    }

    /**
     * Reverse **every** accrual still standing, whatever period raised it.
     *
     * **The invariant this establishes is the whole design: after `open()` runs, the only standing accruals are the ones
     * that run raised.** Wipe and recompute, rather than adjust.
     *
     * The first draft restricted this to *earlier* periods, which read as a faithful transcription of §4.5's "auto-reverse
     * at the opening of the next period" and was wrong — because §4.5 is not the only thing that raises accruals.
     * `GoodsReceiptService` raises one the moment a delivery is posted, since §5's committed-cost report is worthless if a
     * delivery takes a month to appear. A pass that skipped the current period would then find that delivery
     * *outstanding*, raise a second accrual for it, and double the job's accrued cost for the month — the exact failure
     * §4.5 warns about, arrived at from the opposite direction.
     *
     * Repeated runs in one month therefore leave reversal pairs behind. That is honest rather than untidy: each run is a
     * dated event, the net position after any run is correct, and the alternative — netting the re-accrual off against
     * whatever is already accrued — is the line-by-line matching §4.5 rejects by name.
     *
     * **The reversal is itself `kind = accrual`**, with a negative amount, and that is not a detail. §3.5 defines the
     * report's columns as `Actual = Σ amount where kind != accrual` and `Accrued = Σ amount where kind = accrual`. A
     * reversal written as `kind = reversal` would net out of the Accrued column into the Actual one, understating actual
     * cost by exactly the accrual and overstating nothing — a job that looked cheaper for no reason any query could
     * find.
     *
     * **Which is also why this does not call `CostLedger::reverse()`.** That method is right for a correction and wrong
     * here on both counts: it writes `kind = reversal` (see above), and it puts the reversal in the *original's* period,
     * because §3.3 wants a correction to cancel where it happened. An accrual reversal belongs to the **new** period by
     * definition — §4.5 says "at the opening of the next period" — since the whole point is that last month's accrual
     * came off in this month's books.
     *
     * `reverses_id` is what distinguishes a reversal from an accrual in the same `kind`, so the next month's run finds
     * only what is genuinely outstanding rather than reversing its own reversals forever.
     *
     * @return array{batch: CostBatch|null, count: int, total: float}
     */
    public function reverseStandingAccruals(string $periodStart): array
    {
        $start = CostPeriod::startFor($periodStart)->toDateString();

        $outstanding = CostEntry::query()
            ->where('kind', CostEntry::KIND_ACCRUAL)
            // Not itself a reversal, and not already reversed. Both, because either alone would loop: the first would
            // reverse the reversals and the second would reverse the same accrual every month. There is deliberately no
            // period filter — see the docblock.
            ->whereNull('reverses_id')
            ->whereNull('reversed_by_id')
            ->get();

        if ($outstanding->isEmpty()) {
            return ['batch' => null, 'count' => 0, 'total' => 0.0];
        }

        $batch = CostBatch::create([
            'kind' => CostBatch::KIND_ACCRUAL_REVERSAL,
            'period_start' => $start,
            'description' => 'Accruals reversed on opening '.CostPeriod::startFor($start)->format('F Y'),
            'created_by' => auth()->id(),
        ]);

        $total = 0.0;

        foreach ($outstanding as $entry) {
            $reversal = CostEntry::create([
                'job_id' => $entry->job_id,
                'wbs_node_id' => $entry->wbs_node_id,
                'cost_code_id' => $entry->cost_code_id,
                // The original's type, not the code's as it stands now: a reversal that reclassified itself would leave
                // the pair failing to cancel in the labour/material split. `CostLedger::reverse()`'s reasoning, and it
                // holds here for the same reason.
                'cost_type' => $entry->cost_type,
                'kind' => CostEntry::KIND_ACCRUAL,
                'amount' => -1 * (float) $entry->amount,
                'quantity' => $entry->quantity === null ? null : -1 * (float) $entry->quantity,
                'unit_of_measure' => $entry->unit_of_measure,
                'unit_rate' => $entry->unit_rate,
                // Dated to the first of the new month, which is what "at the opening of the next period" means.
                'incurred_on' => $start,
                'posting_period' => $start,
                'gl_treatment' => $entry->gl_treatment === CostEntry::GL_MEMO
                    ? CostEntry::GL_MEMO
                    : CostEntry::GL_PENDING,
                // The same credit the original owed, so §11a's posting service debits the accrual account back.
                'gl_purpose' => $entry->gl_purpose,
                'reverses_id' => $entry->getKey(),
                'batch_id' => $batch->getKey(),
                'description' => 'Accrual reversed on opening '.CostPeriod::startFor($start)->format('F Y'),
                'reference' => $entry->reference,
                'created_by' => auth()->id(),
            ]);

            $entry->update(['reversed_by_id' => $reversal->getKey()]);
            $total += (float) $entry->amount;
        }

        return ['batch' => $batch, 'count' => $outstanding->count(), 'total' => round($total, 2)];
    }

    /**
     * **Goods received not invoiced**, re-raised for whatever is still outstanding.
     *
     * The delivery already put cost on the job the day it arrived — `GoodsReceiptService` raises that accrual at receipt
     * time, because §5's committed-cost report is worthless if a delivery takes a month to show up. This method covers
     * every month *after* the one it arrived in: the receipt-time accrual was reversed when this month opened, and if the
     * invoice still has not come the cost has to be back on the job.
     *
     * **Received less invoiced, per commitment line**, which is the three-way match's own arithmetic (§5). A receipt
     * line with no order behind it is **reported rather than accrued**: there is no link through which an invoice could
     * ever be matched to it, so "not invoiced" is unanswerable and a guess either way would be wrong on half the
     * deliveries in the country.
     */
    public function accrueGoodsReceivedNotInvoiced(string $periodStart): AccrualResult
    {
        $start = CostPeriod::startFor($periodStart)->toDateString();
        $label = 'Goods received not invoiced';

        /*
         * Received value per commitment line, posted receipts only, and only what was received **on or before** the end
         * of this month. A delivery dated into next month is not an accrual for this one.
         */
        $received = DB::table('construction_goods_receipt_lines as grl')
            ->join('construction_goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->where('gr.status', 'posted')
            ->whereDate('gr.received_on', '<=', CostPeriod::startFor($start)->copy()->endOfMonth()->toDateString())
            ->whereNotNull('grl.commitment_line_id')
            ->groupBy('grl.commitment_line_id', 'grl.job_id', 'grl.cost_code_id', 'grl.wbs_node_id')
            ->selectRaw('grl.commitment_line_id, grl.job_id, grl.cost_code_id, grl.wbs_node_id, SUM(grl.amount) as amount')
            ->get();

        $invoiced = DB::table('construction_invoice_allocations')
            ->whereNotNull('commitment_line_id')
            ->groupBy('commitment_line_id')
            ->selectRaw('commitment_line_id, SUM(amount) as amount')
            ->pluck('amount', 'commitment_line_id');

        $orphans = DB::table('construction_goods_receipt_lines as grl')
            ->join('construction_goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->where('gr.status', 'posted')
            ->whereNull('grl.commitment_line_id')
            ->count();

        $skipped = [];

        if ($orphans > 0) {
            $skipped[] = "{$orphans} posted receipt line(s) name no purchase order, so whether they have been invoiced "
                .'cannot be established and they were not accrued. Raise the order, or record the cost from the '
                .'supplier invoice when it arrives.';
        }

        $rows = [];

        foreach ($received as $row) {
            $outstanding = round((float) $row->amount - (float) ($invoiced[$row->commitment_line_id] ?? 0), 2);

            // Nothing outstanding, or over-invoiced. Over-invoicing is a three-way-match variance and not an accrual —
            // a negative accrual here would credit a job for money the supplier has already billed.
            if ($outstanding <= 0.005) {
                continue;
            }

            $rows[] = [
                'job_id' => (int) $row->job_id,
                'cost_code_id' => (int) $row->cost_code_id,
                'wbs_node_id' => $row->wbs_node_id === null ? null : (int) $row->wbs_node_id,
                'amount' => $outstanding,
                'reference' => 'GRNI',
                'description' => 'Goods received not invoiced at '
                    .CostPeriod::startFor($start)->copy()->endOfMonth()->toDateString(),
            ];
        }

        return $this->raise($label, 'grni', $start, $rows, $skipped);
    }

    /**
     * **Subcontract work done not certified.**
     *
     * A subcontractor has claimed for work and nobody has certified it yet. The work is done, the cost is real, and
     * until the certificate is issued nothing in the books knows about it — which is exactly what an accrual is for.
     *
     * **Per contract item, because that is the only level that names a cost code.** A contract-level accrual would have
     * to pick one code for a subcontract spanning six, and picking would be a guess. An item with no cost code is
     * reported rather than guessed at, on the same principle.
     *
     * The claim has to be *live*: `submitted` or `under_review`. A `draft` claim is a subcontractor's working paper
     * nobody has received, and accruing off it would put cost on a job on the strength of a document that may never be
     * sent. A `certified` one is no longer an accrual — the certificate is the real cost.
     */
    public function accrueSubcontractWorkNotCertified(string $periodStart): AccrualResult
    {
        $start = CostPeriod::startFor($periodStart)->toDateString();
        $periodEnd = CostPeriod::startFor($start)->copy()->endOfMonth()->toDateString();
        $label = 'Subcontract work done not certified';

        /*
         * The live claim lines on payable contracts, valued to this month's end.
         *
         * `side = 'payable'` is what makes a contract a subcontract (§8.1's one table for both), and the join to the
         * job is through the contract rather than the claim, because a claim belongs to a contract and a contract to a
         * job.
         */
        $claimed = DB::table('construction_progress_claim_lines as pcl')
            ->join('construction_progress_claims as pc', 'pc.id', '=', 'pcl.progress_claim_id')
            ->join('construction_contracts as c', 'c.id', '=', 'pc.contract_id')
            ->join('construction_contract_items as ci', 'ci.id', '=', 'pcl.contract_item_id')
            ->where('c.side', 'payable')
            ->whereIn('pc.status', ['submitted', 'under_review'])
            ->whereDate('pc.period_end', '<=', $periodEnd)
            ->select([
                'pcl.contract_item_id',
                'c.job_id',
                'c.contract_number',
                'ci.cost_code_id',
                'ci.wbs_node_id',
                'ci.item_no',
                DB::raw('pcl.cumulative_work_value + pcl.cumulative_materials_value as claimed'),
                'pc.period_end',
            ])
            ->orderBy('pc.period_end')
            ->get()
            // The latest live claim wins per item: a subcontractor who resubmits has one outstanding position, not two.
            ->keyBy('contract_item_id');

        if ($claimed->isEmpty()) {
            return AccrualResult::nothing($label);
        }

        /*
         * What has already been certified per item, cumulative, from **issued or paid** certificates only.
         *
         * A draft certificate certifies nothing — §10.1 keeps the claim and the certificate apart precisely because the
         * certifier's figure is not the claimant's — and a void one has been withdrawn. Counting either would net an
         * accrual off against a document nobody has signed.
         */
        $certified = DB::table('construction_certificate_lines as cl')
            ->join('construction_payment_certificates as pcert', 'pcert.id', '=', 'cl.payment_certificate_id')
            ->whereIn('pcert.status', ['issued', 'paid'])
            ->whereIn('cl.contract_item_id', $claimed->keys()->all())
            ->groupBy('cl.contract_item_id')
            ->selectRaw('cl.contract_item_id, MAX(cl.cumulative_work_value + cl.cumulative_materials_value) as certified')
            ->pluck('certified', 'contract_item_id');

        $rows = [];
        $noCode = 0;

        foreach ($claimed as $itemId => $row) {
            $outstanding = round((float) $row->claimed - (float) ($certified[$itemId] ?? 0), 2);

            if ($outstanding <= 0.005) {
                continue;
            }

            if ($row->cost_code_id === null) {
                $noCode++;

                continue;
            }

            $rows[] = [
                'job_id' => (int) $row->job_id,
                'cost_code_id' => (int) $row->cost_code_id,
                'wbs_node_id' => $row->wbs_node_id === null ? null : (int) $row->wbs_node_id,
                'amount' => $outstanding,
                'reference' => $row->contract_number,
                'description' => "Work done not certified — {$row->contract_number} item {$row->item_no}",
            ];
        }

        $skipped = $noCode === 0 ? [] : [
            "{$noCode} subcontract item(s) with work claimed and not certified carry no cost code, so there is nowhere "
            .'to book the accrual. Set a cost code on the contract item — a contract-level accrual would have to pick '
            .'one code for a subcontract spanning six, and picking would be a guess.',
        ];

        return $this->raise($label, 'subcontract_accrual', $start, $rows, $skipped);
    }

    /**
     * Write one accrual pass as a batch of entries.
     *
     * **Through `CostLedger::record()`, not straight into the table**, so the rules that method owns still apply: a
     * heading code is refused, a switched-off code is refused, the cost type is snapshotted off the code, and a closed
     * period lands the entry in the earliest open one with its real date kept. Bypassing it to save a query is how a
     * bulk process comes to be the one path in a module that does not obey the module's own rules.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $skipped
     */
    private function raise(string $label, string $purpose, string $periodStart, array $rows, array $skipped): AccrualResult
    {
        if ($rows === []) {
            return AccrualResult::nothing($label, $skipped);
        }

        $batch = CostBatch::create([
            'kind' => CostBatch::KIND_ACCRUAL,
            'period_start' => $periodStart,
            'description' => $label.' — '.CostPeriod::startFor($periodStart)->format('F Y'),
            'created_by' => auth()->id(),
        ]);

        $ledger = app(CostLedger::class);
        $incurredOn = Carbon::parse($periodStart)->endOfMonth()->toDateString();
        $total = 0.0;
        $count = 0;
        $refused = [];

        foreach ($rows as $row) {
            $job = Job::query()->find($row['job_id']);
            $code = CostCode::query()->find($row['cost_code_id']);

            if ($job === null || $code === null) {
                continue;
            }

            try {
                $ledger->record($job, $code, [
                    'kind' => CostEntry::KIND_ACCRUAL,
                    'gl_purpose' => $purpose,
                    'amount' => $row['amount'],
                    'incurred_on' => $incurredOn,
                    'wbs_node_id' => $row['wbs_node_id'],
                    'batch_id' => $batch->getKey(),
                    'description' => $row['description'],
                    'reference' => $row['reference'],
                ]);

                $total += $row['amount'];
                $count++;
            } catch (InvalidArgumentException $e) {
                /*
                 * A refused code is named rather than swallowed.
                 *
                 * `CostLedger::record()` refuses a heading code and a switched-off one, and both are real states a
                 * contract item can be pointing at. Losing the accrual silently would leave a subcontract's work
                 * nowhere with a healthy-looking total above it, which is the failure §18.1 keeps naming.
                 */
                $refused[] = number_format($row['amount'], 2).' for '.$row['description'].' could not be accrued: '
                    .$e->getMessage();
            }
        }

        if ($count === 0) {
            $batch->delete();

            return AccrualResult::nothing($label, [...$skipped, ...$refused]);
        }

        return new AccrualResult($label, $batch, $count, round($total, 2), [...$skipped, ...$refused]);
    }
}
