<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Commitments and their relief — `docs/construction-management-plan.md` §5.
 *
 * Three rules live here rather than in a form, because a receipt, a subcontract certificate and an invoice
 * allocation are three callers and a rule kept in one of them is a rule the other two walk past.
 *
 *  - **Relief happens once, at the earlier of receipt or certificate, and the invoice relieves only the unreceived
 *    balance.** §5 names double relief as the hazard: the goods receipt relieves, and then the invoice for the same
 *    goods must not relieve again. Get it wrong and the committed column reads as if the order were twice
 *    delivered, which reads as a cost code with room in it that has none.
 *  - **Open commitment is `line.amount − Σ reliefs`, computed.** Phase 5's exit condition is that it is *provable*,
 *    which a stored balance is not: a stored figure is a second place for the same number to live, and the first
 *    thing that goes wrong is a receipt that relieves while the total does not move.
 *  - **Closing with a balance is an act with an author and a reason.** A purchase order that quietly stops changing
 *    is an open commitment nobody will ever clear.
 */
class CommitmentService
{
    /**
     * Open a commitment.
     *
     * The number is issued here rather than by the caller, and per year rather than per job: an order goes to a
     * supplier who has no idea what a job number means, and `PO-2026-0142` is what will come back on the invoice.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes = []): Commitment
    {
        return TenantTransaction::run(fn (): Commitment => Commitment::create($attributes + [
            'number' => $this->nextNumber($attributes['type'] ?? Commitment::TYPE_PURCHASE_ORDER),
            'order_date' => now()->toDateString(),
        ]));
    }

    /**
     * The next number in the year's series for that type.
     *
     * Read off the trailing digits rather than by counting rows, so a cancelled order does not make the next one
     * reuse a number a supplier already has on a piece of paper.
     */
    public function nextNumber(string $type, ?int $year = null): string
    {
        $year ??= (int) now()->year;

        $prefix = match ($type) {
            Commitment::TYPE_SUBCONTRACT => 'SC',
            Commitment::TYPE_PLANT_HIRE => 'PH',
            default => 'PO',
        };

        $used = Commitment::query()
            ->where('type', $type)
            ->where('number', 'like', "{$prefix}-{$year}-%")
            ->pluck('number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return sprintf('%s-%d-%04d', $prefix, $year, $used + 1);
    }

    /**
     * Add a line, which is where the job and the cost code live.
     *
     * A heading code is refused for the same reason it takes no cost: committing against it would double-count in
     * every rolled-up total, and the four-column report reads committed and actual side by side.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(Commitment $commitment, Job $job, CostCode $code, array $attributes): CommitmentLine
    {
        if (! $commitment->isDraft()) {
            throw new InvalidArgumentException(
                "{$commitment->number} is {$commitment->status}. A change to an issued order is a variation to it, "
                .'not an edit — the supplier is working to the copy they were sent.'
            );
        }

        if (! $code->is_leaf) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} is a heading. Committing against it would double-count in every rolled-up "
                .'total, the same way booking cost to it would.'
            );
        }

        if (! $code->is_active) {
            throw new InvalidArgumentException("Cost code {$code->code} is switched off and cannot take new commitment.");
        }

        return $commitment->lines()->create($attributes + [
            'job_id' => $job->getKey(),
            'cost_code_id' => $code->getKey(),
        ]);
    }

    /** Sent for approval. Its own state because "priced and waiting" is where most orders sit for a day or two. */
    public function submit(Commitment $commitment): Commitment
    {
        $this->requireStatus($commitment, [Commitment::STATUS_DRAFT], 'submitted for approval');

        if ($commitment->lines()->doesntExist()) {
            throw new InvalidArgumentException(
                "{$commitment->number} has no lines. There is nothing to approve, and an empty order that reached "
                .'a supplier would commit nothing while looking like an order.'
            );
        }

        return $this->stamp($commitment, ['status' => Commitment::STATUS_PENDING_APPROVAL]);
    }

    /**
     * Approved: this company has decided to spend the money.
     *
     * **Approval alone does not commit it.** The committed column reads issued orders, because an approved order the
     * supplier has not been sent can still be withdrawn with a phone call and no consequence — see
     * `Commitment::COMMITTING_STATUSES`.
     */
    public function approve(Commitment $commitment): Commitment
    {
        $this->requireStatus(
            $commitment,
            [Commitment::STATUS_DRAFT, Commitment::STATUS_PENDING_APPROVAL],
            'approved',
        );

        if ($commitment->lines()->doesntExist()) {
            throw new InvalidArgumentException("{$commitment->number} has no lines to approve.");
        }

        return $this->stamp($commitment, [
            'status' => Commitment::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);
    }

    /**
     * Issued: the supplier has it, and the money is now committed.
     *
     * This is the transition that puts the figure on the four-column report, which is why it is a separate act from
     * approval rather than the same one under two names.
     */
    public function issue(Commitment $commitment): Commitment
    {
        $this->requireStatus($commitment, [Commitment::STATUS_APPROVED], 'issued');

        return $this->stamp($commitment, [
            'status' => Commitment::STATUS_ISSUED,
            'issued_at' => now(),
            'issued_by' => auth()->id(),
        ]);
    }

    /**
     * Relieve a line — the one entry point, so the double-relief rule cannot be bypassed.
     *
     * §5's rule, stated as code: relief happens once, at the earlier of receipt or certificate, and **an invoice
     * relieves only the unreceived balance**. So an invoice for goods already received relieves nothing, and an
     * invoice for goods never received relieves what the receipt would have.
     *
     * Returns null where an invoice has nothing left to relieve, because that is the ordinary case rather than an
     * error: the receipt got there first, which is exactly what should happen.
     */
    public function relieve(
        CommitmentLine $line,
        string $kind,
        float $amount,
        ?Model $source = null,
        ?string $reason = null,
        ?float $quantity = null,
        ?string $on = null,
    ): ?CommitmentRelief {
        if (in_array($kind, CommitmentRelief::REASON_REQUIRED, true) && trim((string) $reason) === '') {
            throw new InvalidArgumentException(
                "A {$kind} needs a reason: it leaves money uncommitted that somebody ordered, and "
                .'"the supplier delivered short and we agreed to leave it" is a different fact from "somebody '
                .'forgot".'
            );
        }

        $amount = round($amount, 2);

        /*
         * The unreceived-balance clamp applies to **invoice relief in the forward direction only**.
         *
         * A negative invoice relief is a give-back — a withdrawn allocation, a credit note — and it has to pass
         * through untouched. Clamping it was a real bug: `min(-10_000_000, 0)` is −10,000,000, which the
         * `<= 0` guard below then threw away, so withdrawing an allocation silently returned no commitment at all
         * and the order stayed relieved for money nobody was being charged. The same shape of mistake as a status
         * that could not reverse — a rule written for one direction quietly blocking the other.
         */
        if ($kind === CommitmentRelief::KIND_INVOICE && $amount > 0.0) {
            // The unreceived balance, and no more. A negative result means the receipt has already relieved more
            // than this invoice covers, which is not an error — it is the receipt having got there first.
            $unreceived = round((float) $line->amount - $line->receivedTotal() - $line->invoicedTotal(), 2);
            $amount = min($amount, max(0.0, $unreceived));

            if ($amount <= 0.0) {
                return null;
            }
        }

        if ($amount === 0.0) {
            return null;
        }

        return TenantTransaction::run(function () use ($line, $kind, $amount, $source, $reason, $quantity, $on): CommitmentRelief {
            $relief = $line->reliefs()->create([
                'kind' => $kind,
                'amount' => $amount,
                'quantity' => $quantity,
                'relieved_on' => Carbon::parse($on ?? now())->toDateString(),
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]);

            /*
             * The order is fetched by key rather than read off `$line->commitment`, and it is not a style
             * preference: lazy loading is disabled application-wide, so the relation read threw for every caller
             * that had not eager-loaded it. It went unnoticed because every order in a test had **one** line —
             * `close()` and `cancel()` loop the lines, and the second one is where it fired. A subcontract order is
             * routinely several lines, so Phase 6c is what found it.
             */
            $this->refreshStatus(Commitment::query()->findOrFail($line->commitment_id));

            return $relief;
        });
    }

    /**
     * Bring the orders behind a contract into line with what has been certified under it — §5, §12, Phase 6c.
     *
     * **The target is cumulative; the row written is the movement.** This is §8's rule arriving one module along: a
     * certificate is a cumulative document, so the honest question is not "how much did certificate 7 add" but "what
     * do the live certificates say has been certified to date" — and the relief row is whatever it takes to get the
     * certificate reliefs on this contract's orders up (or down) to that figure. Three things fall out of it that an
     * incremental design has to handle separately and usually gets wrong:
     *
     *  - Voiding the **latest** certificate gives the commitment back, because the target falls to the previous
     *    certificate's cumulative figure.
     *  - Voiding an **earlier** certificate while a later one stands changes nothing, which is correct and is the
     *    case an incremental "reverse what that certificate relieved" gets backwards — the later certificate already
     *    certified a cumulative figure that includes the earlier work.
     *  - Linking an order to a contract that has already been certified relieves it at once, instead of showing a
     *    fully open commitment against work that is finished and paid for.
     *
     * **The caller passes figures, never certificates.** `construction_contracts` may name this module; nothing here
     * may name that one, or the pair becomes a cycle and neither can be packaged — so what crosses is a cost code to
     * value map and a total, which is the same discipline `App\Support\PayslipSettlement` keeps for payroll.
     *
     * @param  array<int|string, float>  $certifiedByCostCode  cumulative certified value per cost code
     * @param  float  $certifiedTotal  cumulative certified value in total, including anything the schedule left uncoded
     * @param  \Illuminate\Database\Eloquent\Model|null  $source  the certificate the figures came from, for the morph
     * @return array<int, CommitmentRelief> the movements written, which is empty when nothing moved
     */
    public function relieveFromCertification(
        int|string $contractId,
        array $certifiedByCostCode,
        float $certifiedTotal,
        ?Model $source = null,
        ?string $on = null,
    ): array {
        $lines = CommitmentLine::query()
            ->with('reliefs')
            ->whereIn('commitment_id', Commitment::query()
                ->where('contract_id', $contractId)
                ->committing()
                ->select('id'))
            ->get();

        /*
         * No committing order means nothing to relieve, and that is a real state rather than a miss: a subcontract
         * whose order was never issued has put nothing on the committed column, so the certified value arrives as
         * actual cost with no commitment to discharge. Same for a closed or cancelled order, whose remainder was
         * already written off with an author and a reason — relieving it again would double the write-off.
         */
        if ($lines->isEmpty()) {
            return [];
        }

        $targets = $this->certifiedTargets($lines, $certifiedByCostCode, $certifiedTotal);

        $written = [];

        foreach ($lines as $line) {
            $already = round((float) $line->reliefs
                ->where('kind', CommitmentRelief::KIND_CERTIFICATE)
                ->sum('amount'), 2);

            $movement = round(($targets[$line->getKey()] ?? 0.0) - $already, 2);

            if ($movement === 0.0) {
                continue;
            }

            if ($relief = $this->relieve($line, CommitmentRelief::KIND_CERTIFICATE, $movement, $source, null, null, $on)) {
                $written[] = $relief;
            }
        }

        return $written;
    }

    /**
     * Give back everything certification has relieved on one order.
     *
     * For when an order is unlinked from its contract: the certificates no longer say anything about it, so the
     * relief they wrote has to come off. A negative row rather than a delete, following the one convention — "what
     * did we think was committed in March" stays answerable, which is the discipline the cost ledger keeps with
     * reversals.
     *
     * @return array<int, CommitmentRelief>
     */
    public function reverseCertificationRelief(Commitment $commitment, ?string $on = null): array
    {
        $written = [];

        foreach ($commitment->lines()->get() as $line) {
            $relieved = round((float) $line->reliefs()
                ->where('kind', CommitmentRelief::KIND_CERTIFICATE)
                ->sum('amount'), 2);

            if ($relieved === 0.0) {
                continue;
            }

            if ($relief = $this->relieve($line, CommitmentRelief::KIND_CERTIFICATE, -1 * $relieved, null, null, null, $on)) {
                $written[] = $relief;
            }
        }

        return $written;
    }

    /**
     * How much of the certified value each order line carries.
     *
     * **By cost code first, because the schedule already answers the question.** §8.2 calls `cost_code_id` on a
     * contract item "the join to job cost", so the certificate knows which code every certified rupee belongs to,
     * and the four-column report reads committed *per code* — spreading everything pro-rata would leave one code
     * over-committed and another under-committed on the same order, which is the silent misstatement the join
     * exists to prevent.
     *
     * What cannot be matched is spread pro-rata by line value, and that residue is not a rounding crumb: a
     * schedule line with no cost code, or one coded to something the order does not carry, is ordinary. Dropping
     * it would leave commitment open against work that has been certified and paid for, which is worse than
     * approximating where it sits.
     *
     * **Nothing is capped at the line value.** An over-relief is a real condition — the order was priced below
     * what has been certified against it — and `Commitment::overRelieved()` exists to answer it. Clamping here
     * would make the order look complete while hiding the fact that it is short.
     *
     * @param  \Illuminate\Support\Collection<int, CommitmentLine>  $lines
     * @param  array<int|string, float>  $certifiedByCostCode
     * @return array<int|string, float>
     */
    private function certifiedTargets(
        \Illuminate\Support\Collection $lines,
        array $certifiedByCostCode,
        float $certifiedTotal,
    ): array {
        $targets = [];
        $matched = 0.0;
        $byCode = $lines->groupBy('cost_code_id');

        foreach ($certifiedByCostCode as $costCodeId => $value) {
            $group = $byCode->get((int) $costCodeId);

            if ($group === null || (float) $value === 0.0) {
                continue;
            }

            $matched = round($matched + (float) $value, 2);

            foreach ($this->spread($group, (float) $value) as $lineId => $share) {
                $targets[$lineId] = round(($targets[$lineId] ?? 0.0) + $share, 2);
            }
        }

        $residue = round($certifiedTotal - $matched, 2);

        if ($residue !== 0.0) {
            foreach ($this->spreadResidue($lines, $targets, $residue) as $lineId => $share) {
                $targets[$lineId] = round(($targets[$lineId] ?? 0.0) + $share, 2);
            }
        }

        /*
         * The pennies pro-rata rounding loses, put back on the largest line.
         *
         * Without this, `Σ certificate reliefs = certified to date` holds only approximately, and Phase 5's whole
         * point is that open commitment is *provable* rather than about right. A test asserts the equality, which
         * is only assertable because of these three lines.
         */
        $drift = round($certifiedTotal - array_sum($targets), 2);

        if ($drift !== 0.0) {
            $largest = $lines->sortByDesc(fn (CommitmentLine $line): float => (float) $line->amount)->first();
            $targets[$largest->getKey()] = round(($targets[$largest->getKey()] ?? 0.0) + $drift, 2);
        }

        return $targets;
    }

    /**
     * The certified value no cost code could place, spread over the lines that still have room for it.
     *
     * Room rather than order value, and the case that decides it is the ordinary one: a schedule with one coded item
     * and one uncoded one, both fully certified. Spreading the uncoded half by order value would push the coded line
     * past its own amount while leaving the other partly open — an over-relief and an open balance on one order that
     * is in fact wholly certified, which is two wrong figures where the truth is simple.
     *
     * Falls back to order value when there is no room left anywhere, and for a give-back, where "room" is not the
     * question being asked.
     *
     * @param  \Illuminate\Support\Collection<int, CommitmentLine>  $lines
     * @param  array<int|string, float>  $placed  what the cost codes have already accounted for, by line
     * @return array<int|string, float>
     */
    private function spreadResidue(\Illuminate\Support\Collection $lines, array $placed, float $residue): array
    {
        if ($residue < 0.0) {
            return $this->spread($lines, $residue);
        }

        $room = [];

        foreach ($lines as $line) {
            $room[$line->getKey()] = max(0.0, round((float) $line->amount - ($placed[$line->getKey()] ?? 0.0), 2));
        }

        $total = round(array_sum($room), 2);

        if ($total <= 0.0) {
            return $this->spread($lines, $residue);
        }

        $shares = [];

        foreach ($room as $lineId => $available) {
            $shares[$lineId] = round($residue * ($available / $total), 2);
        }

        return $shares;
    }

    /**
     * One value across several lines, in proportion to what each was ordered for.
     *
     * Equal shares where the lines carry no value at all, because dividing by a zero order total would otherwise
     * discard the whole figure — an order of zero-value lines is odd, but losing certified value to it is worse.
     *
     * @param  \Illuminate\Support\Collection<int, CommitmentLine>  $lines
     * @return array<int|string, float>
     */
    private function spread(\Illuminate\Support\Collection $lines, float $value): array
    {
        $total = round((float) $lines->sum(fn (CommitmentLine $line): float => (float) $line->amount), 2);
        $shares = [];

        foreach ($lines as $line) {
            $fraction = $total > 0.0 ? ((float) $line->amount / $total) : (1 / $lines->count());
            $shares[$line->getKey()] = round($value * $fraction, 2);
        }

        return $shares;
    }

    /**
     * Move an issued order between `issued` and `partially_relieved` as reliefs land.
     *
     * Derived from the reliefs rather than set by whoever wrote the last one: a status somebody has to remember to
     * update is a status that disagrees with the rows underneath it. A fully relieved order is **not** closed
     * automatically — closing is a decision, and an order at exactly its value may still take a credit note.
     */
    private function refreshStatus(Commitment $commitment): void
    {
        if ($commitment->isClosed() || ! $commitment->isIssued()) {
            return;
        }

        $relieved = $commitment->relievedTotal();

        $commitment->update([
            'status' => $relieved > 0.0
                ? Commitment::STATUS_PARTIALLY_RELIEVED
                : Commitment::STATUS_ISSUED,
        ]);
    }

    /**
     * Close an order, writing off whatever is still open — **with an author and a reason**.
     *
     * The close-out relief is what makes the write-off visible: the committed column drops by that figure and the
     * ledger says who decided and why. Without it, closing would leave an order whose lines still say money is
     * promised while its status says otherwise, and the two would be read by different reports.
     */
    public function close(Commitment $commitment, string $reason): Commitment
    {
        if ($commitment->isClosed()) {
            throw new InvalidArgumentException("{$commitment->number} is already {$commitment->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Closing an order needs a reason. An order that quietly stops changing is an open commitment '
                .'nobody will ever clear.'
            );
        }

        return TenantTransaction::run(function () use ($commitment, $reason): Commitment {
            foreach ($commitment->lines as $line) {
                if (($open = $line->openAmount()) > 0.0) {
                    $this->relieve($line, CommitmentRelief::KIND_CLOSE_OUT, $open, null, $reason);
                }
            }

            $commitment->update([
                'status' => Commitment::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'close_reason' => $reason,
            ]);

            return $commitment->refresh();
        });
    }

    /**
     * Cancel an order outright, which is different from closing one.
     *
     * Closing says "this is finished, and what is left will not be spent"; cancelling says "this should never have
     * been placed". A cancelled draft has committed nothing and needs no relief; a cancelled *issued* order does,
     * because the committed column has been carrying it.
     */
    public function cancel(Commitment $commitment, string $reason): Commitment
    {
        if ($commitment->isClosed()) {
            throw new InvalidArgumentException("{$commitment->number} is already {$commitment->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancelling an order needs a reason.');
        }

        return TenantTransaction::run(function () use ($commitment, $reason): Commitment {
            if ($commitment->isIssued()) {
                foreach ($commitment->lines as $line) {
                    if (($open = $line->openAmount()) > 0.0) {
                        $this->relieve($line, CommitmentRelief::KIND_CANCELLATION, $open, null, $reason);
                    }
                }
            }

            $commitment->update([
                'status' => Commitment::STATUS_CANCELLED,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'close_reason' => $reason,
            ]);

            return $commitment->refresh();
        });
    }

    // ------------------------------------------------------------------ the reads

    /**
     * **Open commitment per cost code for a job tree — Phase 5's exit condition.**
     *
     * Provable rather than approximate: every figure here is `Σ line.amount − Σ reliefs` over issued orders, and
     * every relief names what caused it. The four-column report reads this, which is what turns its `committed`
     * column from an em dash into a number.
     *
     * @return array<int, float> cost code id => open commitment
     */
    public function openByCode(Job $job): array
    {
        $lines = CommitmentLine::query()
            ->with('reliefs')
            ->forJobTree($job)
            ->committing()
            ->get();

        $open = [];

        foreach ($lines as $line) {
            if (($amount = $line->openAmount()) <= 0.0) {
                continue;
            }

            $open[$line->cost_code_id] = round(($open[$line->cost_code_id] ?? 0.0) + $amount, 2);
        }

        return $open;
    }

    /** One cost code's open commitment, which is what §3.5's forecast rule compares cost to complete against. */
    public function openFor(Job $job, int|string $costCodeId): float
    {
        return $this->openByCode($job)[(int) $costCodeId] ?? 0.0;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function requireStatus(Commitment $commitment, array $allowed, string $target): void
    {
        if (! in_array($commitment->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "{$commitment->number} is {$commitment->status} and cannot be {$target}. "
                .'Allowed from: '.implode(', ', $allowed).'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stamp(Commitment $commitment, array $attributes): Commitment
    {
        $commitment->update($attributes);

        return $commitment->refresh();
    }
}
