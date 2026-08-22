<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Support\ContractVocabulary;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Creating and executing a contract — `docs/construction-management-plan.md` §8.
 *
 * Two rules live here rather than in a form, for §3's reason: a contract will also arrive by import and a
 * subcontract by copy from a template, and a rule enforced on one screen is a rule the other two walk past.
 *
 *  - **Execution freezes the item schedule.** While the contract is draft, `scheduled_value` follows quantity
 *    × rate; at execution it becomes the figure the client signed and stops moving. Nothing else about the
 *    contract is frozen by this act, which is why it is a status change and not a snapshot table.
 *  - **The job's commercial terms seed the contract and then stop being read.** The job carries retention,
 *    advance and damages figures captured at tender (§1); a receivable contract copies them once and is
 *    authoritative afterwards. Two live sources for "what is the retention percentage" is how a certificate
 *    comes to disagree with the contract somebody signed.
 */
class ContractService
{
    /**
     * Open a contract on a job.
     *
     * The standard is **copied down from the job** rather than chosen here — §8.1 — and then frozen on first
     * certification, so a FIDIC Red head contract can sit above bespoke subcontracts without the job having
     * to lie about which family it belongs to.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Job $job, array $attributes = []): Contract
    {
        $side = $attributes['side'] ?? Contract::SIDE_RECEIVABLE;
        $standard = $attributes['contract_standard'] ?? $job->contract_standard ?? ContractVocabulary::FIDIC;

        return TenantTransaction::run(function () use ($job, $attributes, $side, $standard): Contract {
            return Contract::create($attributes + [
                'job_id' => $job->getKey(),
                'side' => $side,
                'contract_standard' => $standard,
                'contract_number' => $this->nextContractNumber($job, $side),
                'title' => $job->name,
                'retention_release_rule' => ContractVocabulary::for($standard)->defaultReleaseRule(),
                'currency_code' => $job->currency_code,
                // The tender capture, read once. See the class docblock on why only once.
                'contact_id' => $side === Contract::SIDE_RECEIVABLE ? $job->client_contact_id : null,
                'contract_sum' => $job->contract_sum ?? 0,
                'retention_percent' => $job->retention_pct,
                'retention_limit_percent' => $job->retention_cap_pct,
                'retention_first_release_pct' => $job->retention_first_release_pct,
                'advance_recovery_start_pct' => $job->advance_recovery_start_pct,
                'advance_recovery_rate_pct' => $job->advance_recovery_rate_pct,
                'liquidated_damages_per_day' => $job->liquidated_damages_per_day,
                'liquidated_damages_cap_pct' => $job->liquidated_damages_cap_pct,
                'payment_terms_days' => $job->payment_terms_days,
                'commencement_date' => $job->commencement_date,
                'contract_completion_date' => $job->planned_completion_date,
                'practical_completion_date' => $job->substantial_completion_date,
                'defects_period_days' => $job->defects_period_days,
            ]);
        });
    }

    /**
     * The next number in the job's series for that side.
     *
     * Per job and per side rather than per year: a job's subcontracts number `SC-1` upward under it, and a
     * gap in a contract series is the kind of thing somebody has to explain later. Follows
     * `Quotation::nextNumber()` in shape.
     */
    public function nextContractNumber(Job $job, string $side): string
    {
        $prefix = $side === Contract::SIDE_PAYABLE ? 'SC' : 'C';

        // Read off the **trailing** segment only. Stripping every non-digit from `J-2026-014-C-1` yields
        // 20260141, and the next contract on the job is then `C-20260142` — the job code eats the series.
        $used = Contract::query()
            ->where('job_id', $job->getKey())
            ->where('side', $side)
            ->pluck('contract_number')
            ->map(fn (string $number): int => (int) (preg_match('/-(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return "{$job->code}-{$prefix}-".($used + 1);
    }

    /**
     * Add a line to a draft schedule.
     *
     * Refused once executed, and the refusal names the alternative: a change to an executed schedule is a
     * variation (§9), which writes its own line carrying `source_variation_id` — and that is exactly how AIA
     * prints change-order lines appended to the continuation sheet.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(Contract $contract, array $attributes): ContractItem
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} is {$contract->status}. Raise a "
                .strtolower($contract->vocabulary()->change()).' rather than editing an executed schedule — '
                .'the scheduled value is the figure the parties signed, and certificates already issued were '
                .'measured against it.'
            );
        }

        return $contract->items()->create($attributes);
    }

    /**
     * Execute the contract, which freezes the schedule.
     *
     * An empty schedule is refused: a contract with a sum and no lines cannot be claimed against at all, and
     * the certificate would have nothing to put in column C.
     */
    public function execute(Contract $contract): Contract
    {
        if (! $contract->isDraft()) {
            throw new InvalidArgumentException("{$contract->contract_number} is already {$contract->status}.");
        }

        if ($contract->items()->doesntExist()) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} has no "
                .strtolower($contract->vocabulary()->itemSchedule())
                .' lines. There would be nothing to certify against.'
            );
        }

        $contract->update([
            'status' => Contract::STATUS_EXECUTED,
            'contract_date' => $contract->contract_date ?? now()->toDateString(),
        ]);

        return $contract->refresh();
    }
}
