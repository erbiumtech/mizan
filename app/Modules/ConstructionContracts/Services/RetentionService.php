<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\RetentionMovement;
use App\Modules\ConstructionField\Services\PunchListService;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * The only writer of retention movements — `docs/construction-management-plan.md` §11.
 *
 * **Certificates never write movements directly**, and that is the whole architectural point of this class. "Two
 * write paths, one forgotten, and nobody reads both registers in the same week — that is how the two figures come
 * to disagree by an amount neither party can explain at final account." The certification service computes the
 * *deduction row*; this service records what that means for the money held, and links the two so the
 * reconciliation can compare them row by row rather than in total.
 *
 * The release rules are **one function with a branch**, which is what §8's three neutral date columns bought:
 *
 *  - **`fidic_two_stage`** — half (or `retention_first_release_pct`) at Taking-Over, the rest at the end of the
 *    Defects Notification Period, computed from `practical_completion_date + defects_period_days` and never
 *    stored.
 *  - **`aia_substantial`** — the balance at Substantial Completion **less a punch-list holdback**, read from §16.4's
 *    open items that affect practical completion. The holdback needs the field module, and **every one of the three
 *    possible answers says which one it is**: no module and the value is unknown rather than nil, no flagged items and
 *    it is genuinely nil, or a figure with the count of items behind it that carry no cost estimate. §18.1 names this
 *    as one of the two places where a healthy-looking zero has to explain itself.
 *  - **`single_stage` / `custom`** — one release at practical completion.
 *
 * Nothing here reads `contract_standard`. The rule is its own column precisely because a FIDIC contract with a
 * negotiated single-stage release is ordinary, and reading the standard would overrule what the parties agreed.
 */
class RetentionService
{
    /**
     * Record what a certificate's retention deduction means for the money held.
     *
     * Idempotent per certificate: called again for the same certificate it replaces its own movement rather than
     * adding a second. Retention is the one figure on a job that two people will each check once and nobody will
     * check twice.
     */
    public function recordFromCertificate(PaymentCertificate $certificate): ?RetentionMovement
    {
        if (! $certificate->isIssued()) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} is {$certificate->status}. Retention is held when a "
                .'certificate is issued, not while it is a draft — a draft is still being argued about.'
            );
        }

        $deduction = $certificate->deductions()
            ->where('kind', CertificateDeduction::KIND_RETENTION)
            ->first();

        if ($deduction === null) {
            return null;
        }

        $contract = $certificate->contract;
        $held = abs((float) $deduction->amount);

        return TenantTransaction::run(function () use ($certificate, $contract, $deduction, $held): RetentionMovement {
            RetentionMovement::query()
                ->where('payment_certificate_id', $certificate->getKey())
                ->where('kind', RetentionMovement::KIND_HELD)
                ->delete();

            return RetentionMovement::create([
                'contract_id' => $contract->getKey(),
                'payment_certificate_id' => $certificate->getKey(),
                'certificate_deduction_id' => $deduction->getKey(),
                'kind' => RetentionMovement::KIND_HELD,
                'stage' => RetentionMovement::STAGE_INTERIM,
                // Positive: held.
                'amount' => $held,
                'basis_gross' => $certificate->gross_value_to_date,
                'rate_applied' => $contract->retention_percent,
                // Why a movement is smaller than rate × basis. Asked exactly once per job, at the worst moment.
                'cap_reached' => $this->capReached($contract, $certificate),
                'approved_by' => auth()->id(),
            ]);
        });
    }

    private function capReached(Contract $contract, PaymentCertificate $certificate): bool
    {
        $cap = $contract->retentionCap();

        if ($cap === null) {
            return false;
        }

        return round((float) $certificate->retention_to_date, 2) >= round($cap, 2);
    }

    /**
     * The money currently held: the sum of every movement.
     *
     * Computed, always. A stored balance is a number two registers would each maintain, which is the failure §11
     * is written to prevent.
     */
    public function balance(Contract $contract): float
    {
        return round((float) RetentionMovement::query()
            ->where('contract_id', $contract->getKey())
            ->sum('amount'), 2);
    }

    /**
     * The release schedule for a contract — **one function with a branch** (§11).
     *
     * Returns what *should* be released and when, without writing anything. Read by the notification run, by the
     * register, and by `release()` itself, so the three cannot disagree about what is due.
     *
     * @return array<int, array{stage: string, due_on: string|null, amount: float, note: string|null}>
     */
    public function schedule(Contract $contract): array
    {
        $balance = $this->balance($contract);

        if ($balance <= 0.0) {
            return [];
        }

        $completion = $contract->practical_completion_date;
        $expiry = $contract->defectsPeriodExpiry();

        return match ($contract->retention_release_rule) {
            Contract::RELEASE_FIDIC_TWO_STAGE => $this->twoStageSchedule($contract, $balance, $completion, $expiry),
            Contract::RELEASE_AIA_SUBSTANTIAL => $this->aiaSchedule($contract, $balance, $completion, $expiry),
            default => [[
                'stage' => RetentionMovement::STAGE_FINAL_RELEASE,
                'due_on' => $completion?->toDateString(),
                'amount' => $balance,
                'note' => null,
            ]],
        };
    }

    /**
     * @return array<int, array{stage: string, due_on: string|null, amount: float, note: string|null}>
     */
    private function twoStageSchedule(Contract $contract, float $balance, ?Carbon $completion, ?Carbon $expiry): array
    {
        $firstPct = (float) ($contract->retention_first_release_pct ?? 50);
        $alreadyReleased = $this->releasedAtStage($contract, RetentionMovement::STAGE_FIRST_RELEASE);

        // The first release is a share of what was held at completion, so releasing it twice is impossible: the
        // share already released is taken off.
        $totalHeld = $this->totalHeld($contract);
        $first = max(0.0, round($totalHeld * $firstPct / 100, 2) - $alreadyReleased);

        return [
            [
                'stage' => RetentionMovement::STAGE_FIRST_RELEASE,
                'due_on' => $completion?->toDateString(),
                'amount' => min($first, $balance),
                'note' => $completion === null
                    // Not "nothing is due" — "we cannot tell yet", which is a different sentence and the one §18.1
                    // insists on where a zero would otherwise look healthy.
                    ? 'Not yet due: no '.strtolower($contract->vocabulary()->completionEvent()).' date is recorded.'
                    : null,
            ],
            [
                'stage' => RetentionMovement::STAGE_FINAL_RELEASE,
                'due_on' => $expiry?->toDateString(),
                'amount' => round(max(0.0, $balance - min($first, $balance)), 2),
                'note' => $expiry === null
                    ? 'Not yet due: the '.strtolower($contract->vocabulary()->defectsPeriod())
                        .' expiry needs a completion date and a period in days.'
                    : null,
            ],
        ];
    }

    /**
     * AIA: the balance at Substantial Completion, less a punch-list holdback.
     *
     * **The holdback is zero without the field module, and the movement says so in words.** §18.1 names this as
     * one of two places where "smaller, never broken" is not enough, because a zero holdback looks exactly like a
     * job with no outstanding punch items — and releasing the whole balance on a job with fifty open items is
     * money that does not come back.
     *
     * @return array<int, array{stage: string, due_on: string|null, amount: float, note: string|null}>
     */
    private function aiaSchedule(Contract $contract, float $balance, ?Carbon $completion, ?Carbon $expiry): array
    {
        [$holdback, $note] = $this->punchHoldback($contract);

        $alreadyReleased = $this->releasedAtStage($contract, RetentionMovement::STAGE_FIRST_RELEASE);
        $first = max(0.0, round($balance - $holdback, 2) - $alreadyReleased);

        return [
            [
                'stage' => RetentionMovement::STAGE_FIRST_RELEASE,
                'due_on' => $completion?->toDateString(),
                'amount' => min($first, $balance),
                'note' => $completion === null
                    ? 'Not yet due: no substantial completion date is recorded.'
                    : $note,
            ],
            [
                'stage' => RetentionMovement::STAGE_FINAL_RELEASE,
                'due_on' => ($contract->final_completion_date ?? $expiry)?->toDateString(),
                'amount' => round(max(0.0, $balance - min($first, $balance)), 2),
                'note' => $note,
            ],
        ];
    }

    /**
     * **The punch-list holdback, and the sentence that explains whichever figure comes back** — §11 and §16.4.
     *
     * Built in Phase 9f, and it closes a note that had been unconditional since 9a. The reasoning is worth keeping,
     * because it is a mistake this suite makes easy: the guard used to read `modules()->enabled('construction_field')`
     * on the assumption the module and the punch list would arrive together. Phase 9a licensed the module for the delay
     * clock and left punch lists three sub-phases later, so the guard began answering *"the module is here"* while the
     * question it actually asks is *"is there an open punch value to read"*. **A licence is not a proxy for data
     * existing**, and taking the holdback as zero because a table was empty for the wrong reason is the healthy-looking
     * figure hiding an absence §18.1 names as one of its two exceptions.
     *
     * So there are now three answers and each says which one it is:
     *
     *  - **No field module.** Nothing records punch items, so the value is unknown rather than nil, and the release says
     *    so. Releasing the whole balance on a job with fifty open items is money that does not come back.
     *  - **Items open, priced.** The sum of `cost_to_rectify` over open items carrying `affects_practical_completion` —
     *    not every open item, because most snags are paint and sealant and holding retention against all of them makes
     *    the figure meaningless within a week.
     *  - **Items open, some unpriced.** The figure *and* the count of items behind it with no estimate. A holdback of
     *    40,000 across twelve items where three have never been priced is not a holdback of 40,000, and the certifier
     *    is told rather than left to discover it.
     *
     * A guarded coupling, and the direction is the one this module already has: `construction_contracts` names
     * `construction_field`, never the reverse, so the module graph stays acyclic.
     *
     * @return array{0: float, 1: string}
     */
    private function punchHoldback(Contract $contract): array
    {
        if (! modules()->enabled('construction_field')) {
            return [0.0, 'Punch-list holdback taken as zero: site operations is not licensed, so open punch items are '
                .'not recorded anywhere and the value is unknown rather than nil.'];
        }

        // By key unless the caller already loaded it: reading the relation on a contract fetched without it is a lazy
        // load, which this application refuses — and every screen reaching here fetched the contract, not its job.
        $job = $contract->relationLoaded('job')
            ? $contract->job
            : Job::query()->find($contract->job_id);

        if ($job === null) {
            return [0.0, 'Punch-list holdback taken as zero: this contract names no job, so there is no punch list to '
                .'read.'];
        }

        $holdback = app(PunchListService::class)->holdbackFor($job);

        if ($holdback['items'] === 0) {
            return [0.0, 'Punch-list holdback nil: no open punch item on this job is marked as affecting practical '
                .'completion.'];
        }

        $note = "Punch-list holdback {$holdback['items']} item(s) affecting practical completion, "
            .number_format($holdback['amount'], 2).' to rectify.';

        if ($holdback['unpriced'] > 0) {
            // The figure has to say what it is missing. See the method docblock.
            $note .= " {$holdback['unpriced']} of them carry no cost estimate, so the holdback is at least this and "
                .'not exactly this.';
        }

        return [$holdback['amount'], $note];
    }

    /**
     * Release retention at a stage.
     *
     * Refuses to release more than is held, and refuses before the trigger date unless somebody says why — an
     * early release is a real thing (FIDIC 14.9's sectional taking-over) and it is a decision with a name on it
     * rather than a date check to be waved through.
     */
    public function release(
        Contract $contract,
        string $stage = RetentionMovement::STAGE_FIRST_RELEASE,
        ?float $amount = null,
        ?string $reason = null,
    ): RetentionMovement {
        $planned = collect($this->schedule($contract))->firstWhere('stage', $stage);

        if ($planned === null) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} has no {$stage} due — there is nothing held to release."
            );
        }

        $balance = $this->balance($contract);
        $toRelease = round($amount ?? (float) $planned['amount'], 2);

        if ($toRelease <= 0.0) {
            throw new InvalidArgumentException(
                'There is nothing to release at that stage.'
                .($planned['note'] === null ? '' : ' '.$planned['note'])
            );
        }

        if ($toRelease > $balance) {
            throw new InvalidArgumentException(
                'Releasing '.number_format($toRelease, 2).' would exceed the '.number_format($balance, 2)
                .' actually held.'
            );
        }

        $due = $planned['due_on'];
        $early = $due === null || Carbon::parse($due)->isFuture();

        if ($early && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException(
                'This release is not yet due'
                .($planned['note'] === null ? '' : ' — '.$planned['note'])
                .'. An early release needs a reason: sectional taking-over is ordinary, and it is a decision '
                .'somebody has to own.'
            );
        }

        return TenantTransaction::run(fn (): RetentionMovement => RetentionMovement::create([
            'contract_id' => $contract->getKey(),
            'kind' => RetentionMovement::KIND_RELEASED,
            'stage' => $early ? RetentionMovement::STAGE_EARLY_RELEASE : $stage,
            // Negative: released.
            'amount' => -1 * $toRelease,
            'due_on' => $due,
            'released_on' => now()->toDateString(),
            'reason' => $reason,
            'approved_by' => auth()->id(),
        ]));
    }

    /**
     * Forfeit, substitute for a bond, adjust or reinstate — the decisions §11 keeps this a ledger for.
     *
     * Each needs a reason, and the requirement is not ceremony: these are the movements a final account argues
     * about, and the reason column is the only thing that will answer the argument.
     */
    public function record(
        Contract $contract,
        string $kind,
        float $amount,
        string $reason,
        string $stage = RetentionMovement::STAGE_INTERIM,
    ): RetentionMovement {
        if (in_array($kind, RetentionMovement::REASON_REQUIRED, true) && trim($reason) === '') {
            throw new InvalidArgumentException("A {$kind} movement needs a reason.");
        }

        if ($kind === RetentionMovement::KIND_HELD) {
            throw new InvalidArgumentException(
                'Retention is held by issuing a certificate, not by hand — otherwise the ledger and the '
                .'certificates would each hold their own version of the same money.'
            );
        }

        // A forfeit or a substitution reduces what is held; both arrive as a positive figure and are signed here,
        // so a caller cannot accidentally increase the balance with a deduction.
        $signed = in_array($kind, [
            RetentionMovement::KIND_FORFEITED,
            RetentionMovement::KIND_SUBSTITUTED_BY_BOND,
            RetentionMovement::KIND_RELEASED,
        ], true) ? -1 * abs($amount) : $amount;

        if ($signed < 0 && abs($signed) > $this->balance($contract)) {
            throw new InvalidArgumentException(
                number_format(abs($signed), 2).' exceeds the '.number_format($this->balance($contract), 2)
                .' held on this contract.'
            );
        }

        return TenantTransaction::run(fn (): RetentionMovement => RetentionMovement::create([
            'contract_id' => $contract->getKey(),
            'kind' => $kind,
            'stage' => $stage,
            'amount' => round($signed, 2),
            'released_on' => $signed < 0 ? now()->toDateString() : null,
            'reason' => $reason,
            'approved_by' => auth()->id(),
        ]));
    }

    /**
     * The reconciliation §11 asks for: the ledger against the latest certificate's cumulative figure.
     *
     * **Notifies rather than throws**, and the difference is deliberate: a nightly command that threw would stop
     * running, and a register nobody reconciles is exactly the state this exists to detect. The difference is
     * expected to be non-zero on any job where retention has been released — which is why the comparison is
     * against held movements rather than the whole balance.
     *
     * @return array{held: float, certified: float, difference: float, balance: float, explained: bool}
     */
    public function reconcile(Contract $contract): array
    {
        $held = round((float) RetentionMovement::query()
            ->where('contract_id', $contract->getKey())
            ->held()
            ->sum('amount'), 2);

        $certified = round((float) (PaymentCertificate::query()
            ->where('contract_id', $contract->getKey())
            ->live()
            ->orderByDesc('sequence')
            ->value('retention_to_date') ?? 0), 2);

        return [
            'held' => $held,
            'certified' => $certified,
            'difference' => round($held - $certified, 2),
            'balance' => $this->balance($contract),
            'explained' => round($held - $certified, 2) == 0.0,
        ];
    }

    private function totalHeld(Contract $contract): float
    {
        return round((float) RetentionMovement::query()
            ->where('contract_id', $contract->getKey())
            ->held()
            ->sum('amount'), 2);
    }

    private function releasedAtStage(Contract $contract, string $stage): float
    {
        return round(abs((float) RetentionMovement::query()
            ->where('contract_id', $contract->getKey())
            ->whereIn('stage', [$stage, RetentionMovement::STAGE_EARLY_RELEASE])
            ->releases()
            ->sum('amount')), 2);
    }
}
