<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Modules\ConstructionCosting\Services\CommitmentService;

/**
 * The subcontract certificate relieving its commitment — `docs/construction-management-plan.md` §5, §12, Phase 6c.
 *
 * A subcontract is two rows and one agreement (§5's resolution): the **contract** carries the schedule, the
 * variations, the certificates and the retention, and the **commitment** carries the money promised. So certifying
 * work downward has to discharge the promise, or the four-column report goes on saying the money is committed for
 * the whole life of the job — and a cost code with committed money that has already been certified and paid reads
 * as a code with no room left in it. §5 puts the relief at "the earlier of receipt or certificate" precisely so
 * that this path exists.
 *
 * **This module may name `construction_costing`; that module may not name this one.** Neither declares the other —
 * §18 sells certification without cost control and cost control without certification — so the pair is a guarded
 * coupling, recorded in `ModuleBoundaryTest::KNOWN_COUPLINGS`, and the direction is forced: it has to be
 * one-way or the two are a cycle that composer cannot express, and only one direction is any use. That is why
 * `commitments.contract_id` is set from the **contract's** screen rather than by a picker on the order form,
 * which would have been the obvious place for it.
 *
 * Guarded rather than required, in the same shape as `CertificateInvoiceService`: without `construction_costing`
 * this does nothing at all and a certificate is issued exactly as it was before Phase 6c, because a contractor
 * who runs certification and keeps its cost control elsewhere has no commitment ledger to relieve.
 *
 * **What this deliberately does not do is cost the job.** The certified value reaches the cost ledger through the
 * certificate's purchase invoice and its allocation (§10.4, §5), and writing a cost entry here as well would
 * double the cost of every subcontract with nothing disagreeing. Relief is not cost: it says the money is no
 * longer *promised*, which is a different sentence from saying it has been *spent*.
 */
class CertificateCommitmentService
{
    public function canRelieve(): bool
    {
        return modules()->enabled('construction_costing');
    }

    /**
     * Bring the orders behind a contract into line with what its live certificates say has been certified.
     *
     * Called on issue and on void, and safe to call at any other time: the figure it drives towards is the
     * cumulative one on the **latest live certificate**, so calling it twice writes nothing the second time.
     * Cumulative rather than a sum over the certificates because that is what a certificate stores (§8) — summing
     * them would double-count every period.
     *
     * The receivable side returns early rather than being refused, because there is nothing wrong with the
     * question: money coming in from an employer was never committed to anybody, so the answer is that there is
     * nothing to relieve.
     *
     * @return array<int, CommitmentRelief> the movements written, empty when nothing moved
     */
    public function syncFor(Contract $contract, ?string $on = null): array
    {
        if (! $this->canRelieve() || $contract->side !== Contract::SIDE_PAYABLE) {
            return [];
        }

        $latest = PaymentCertificate::query()
            ->with('lines')
            ->where('contract_id', $contract->getKey())
            ->live()
            ->orderByDesc('sequence')
            ->first();

        /*
         * No live certificate left — every one voided — is a target of zero, not a reason to stop. Returning early
         * would leave the commitment relieved for work that no longer stands certified, which is the state nobody
         * would ever find: the order would read as delivered with no certificate saying so.
         */
        return app(CommitmentService::class)->relieveFromCertification(
            $contract->getKey(),
            $latest ? $this->certifiedByCostCode($latest) : [],
            $latest ? (float) $latest->gross_value_to_date : 0.0,
            $latest,
            $on,
        );
    }

    /**
     * Cumulative certified value per cost code, from the certificate's own frozen lines.
     *
     * Read off the certificate rather than recomputed from the schedule, because an issued certificate is a
     * statement of a moment: the relief has to follow the figures somebody countersigned, not the figures the
     * schedule would produce today.
     *
     * Work **and** materials on site, because both are certified and both are paid for. Gross of retention, and
     * that is a decision rather than an oversight: retention is cash withheld against a promise that has already
     * been performed, so netting it off would leave a tenth of every subcontract permanently committed with
     * nothing left to relieve it — the order would never close.
     *
     * A line whose contract item names no cost code is not in this map. Its value is still in the total the
     * caller passes, and `CommitmentService` spreads what it cannot match rather than dropping it.
     *
     * @return array<int, float>
     */
    private function certifiedByCostCode(PaymentCertificate $certificate): array
    {
        $codes = ContractItem::query()
            ->whereIn('id', $certificate->lines->pluck('contract_item_id')->filter()->all())
            ->whereNotNull('cost_code_id')
            ->pluck('cost_code_id', 'id');

        $certified = [];

        foreach ($certificate->lines as $line) {
            $costCodeId = $codes[$line->contract_item_id] ?? null;

            if ($costCodeId === null) {
                continue;
            }

            $value = (float) $line->cumulative_work_value + (float) $line->cumulative_materials_value;

            $certified[(int) $costCodeId] = round(($certified[(int) $costCodeId] ?? 0.0) + $value, 2);
        }

        return $certified;
    }
}
