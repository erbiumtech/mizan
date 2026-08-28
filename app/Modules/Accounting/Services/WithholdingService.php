<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\WithholdingDeduction;
use App\Modules\Accounting\Models\WithholdingSection;
use App\Modules\Core\Models\FiscalYear;
use App\Support\ModuleMap;
use RuntimeException;

/**
 * Tax withheld at source from a supplier payment — `docs/erpnext-gap-plan.md` Phase 4.
 *
 * §149 withholding on salaries was already here, in payroll. §153 — the deduction a company makes from what
 * it pays contractors and suppliers — was not, so it had to be typed as a journal line and remembered, and
 * the §165 statement assembled by hand from a year of them.
 *
 * **The whole feature is one line on an entry that is already posted.** The plan's item says "the same place
 * the second-approver rule already runs, so the flow gains a line and not a step", and that is exactly what
 * `PaymentService::postEntryFor()` does with what this returns: the payable is still debited the gross, cash
 * is credited the net, and the difference is credited to the liability. Nothing new to approve, nothing new
 * to remember, and no deduction possible on a payment nobody approved.
 *
 * **It is off until somebody turns it on, per supplier.** `due()` returns null unless the payee is a
 * beneficiary *and* that beneficiary has a section assigned, which no existing row does. That is what makes
 * this phase additive in the sense the gap plan means: half-finished, it changes nothing.
 */
class WithholdingService
{
    /**
     * What must be withheld from this payment, or null when nothing must be.
     *
     * Null covers most of the reasons there is no deduction: not a beneficiary, no section assigned, the
     * section not in force on the day, a zero payment, or a threshold not reached. The one thing it does not
     * cover is a section with nowhere to post — see `accountFor()`, which throws.
     *
     * @return array{section: WithholdingSection, account_id: int, rate: float, taxable: float, amount: float, was_filer: bool}|null
     */
    public function due(Payment $payment): ?array
    {
        $beneficiary = $payment->payable;

        if (! $beneficiary instanceof Beneficiary) {
            return null;
        }

        $section = $beneficiary->withholdingSection;
        $on = ($payment->value_date ?? now())->toDateString();

        if (! $section || ! $this->inForce($section, $on)) {
            return null;
        }

        $gross = round((float) $payment->amount, 2);

        if ($gross <= 0) {
            return null;
        }

        if (! $this->thresholdReached($section, $beneficiary, $payment, $gross, $on)) {
            return null;
        }

        $isFiler = (bool) $beneficiary->is_filer;
        $rate = $section->rateFor($isFiler);

        if ($rate <= 0) {
            return null;
        }

        return [
            'section' => $section,
            'account_id' => $this->accountFor($section),
            'rate' => $rate,
            'taxable' => $gross,
            'amount' => round($gross * $rate / 100, 2),
            'was_filer' => $isFiler,
        ];
    }

    /**
     * Write the deduction down, once the entry it belongs to exists.
     *
     * Separate from `due()` because the caller needs the figures *before* it can build the entry — the
     * withheld amount is one of the entry's own lines — and the row cannot be written until the entry has an
     * id to point at. Two calls, in that order, and `payment_id` is unique so a second one is refused by the
     * database rather than by a check somebody could forget.
     *
     * @param  array{section: WithholdingSection, rate: float, taxable: float, amount: float, was_filer: bool}  $withholding
     */
    public function record(Payment $payment, array $withholding, ?JournalEntry $entry = null): WithholdingDeduction
    {
        return WithholdingDeduction::create([
            'payment_id' => $payment->getKey(),
            'withholding_section_id' => $withholding['section']->getKey(),
            'beneficiary_id' => $payment->payable instanceof Beneficiary ? $payment->payable->getKey() : null,
            'taxable_amount' => $withholding['taxable'],
            'rate' => $withholding['rate'],
            'amount' => $withholding['amount'],
            'was_filer' => $withholding['was_filer'],
            'deducted_on' => ($payment->value_date ?? now())->toDateString(),
            'journal_entry_id' => $entry?->getKey(),
        ]);
    }

    /** What has already been withheld from this payment. Zero for all but the few that have a section. */
    public function withheldFrom(Payment $payment): float
    {
        return round((float) ($payment->withholdingDeduction?->amount ?? 0), 2);
    }

    /**
     * The §165 statement for a period: every deduction, with what the authority asks for it.
     *
     * Built from `withholding_deductions` and not from the ledger, which is the reason that table exists. A
     * journal line knows the amount; the statement has to state the payee, their NTN, the section, the gross
     * the rate was applied to and the rate — five things a credit to 2100 does not carry.
     *
     * Ordered by date and then by key, so a statement filed twice from the same data is the same document
     * both times.
     *
     * @return array{
     *     from: string, to: string, rows: array<int, array<string, mixed>>,
     *     totals: array{taxable: float, withheld: float, count: int, payees: int, non_filers: int},
     *     sections: array<string, float>
     * }
     */
    public function statement(string $from, string $to): array
    {
        $deductions = WithholdingDeduction::query()
            ->between($from, $to)
            ->with(['section', 'beneficiary', 'payment.payable'])
            ->orderBy('deducted_on')
            ->orderBy('id')
            ->get();

        $rows = $deductions->map(fn (WithholdingDeduction $deduction): array => [
            'date' => $deduction->deducted_on?->toDateString() ?? '',
            'payee' => $deduction->payeeName(),
            'identity' => $deduction->payeeIdentity() ?? '—',
            'section' => (string) $deduction->section?->section,
            'label' => (string) $deduction->section?->label,
            'taxable' => round((float) $deduction->taxable_amount, 2),
            'rate' => round((float) $deduction->rate, 3),
            'withheld' => round((float) $deduction->amount, 2),
            'filer' => (bool) $deduction->was_filer,
            'payment_id' => $deduction->payment_id,
        ])->all();

        $sections = [];

        foreach ($rows as $row) {
            $key = $row['section'] !== '' ? $row['section'] : 'Unknown section';
            $sections[$key] = round(($sections[$key] ?? 0) + $row['withheld'], 2);
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'taxable' => round(array_sum(array_column($rows, 'taxable')), 2),
                'withheld' => round(array_sum(array_column($rows, 'withheld')), 2),
                'count' => count($rows),
                'payees' => count(array_unique(array_column($rows, 'payee'))),
                // Counted because it is the figure that gets a company asked questions: a statement that is
                // mostly non-filers at double rate is either a real supplier base or a beneficiary list
                // nobody has updated since these suppliers started filing.
                'non_filers' => count(array_filter($rows, fn (array $row): bool => ! $row['filer'])),
            ],
            'sections' => $sections,
        ];
    }

    /**
     * Is the section live on the day the money moves?
     *
     * Asked of the row already loaded on the beneficiary rather than by re-querying with `scopeOn()`, so
     * assigning a lapsed section is a deduction of nothing rather than a lazy-load violation on a screen
     * that had no reason to eager-load it.
     */
    private function inForce(WithholdingSection $section, string $on): bool
    {
        if (! $section->is_active) {
            return false;
        }

        if ($section->effective_from && $section->effective_from->toDateString() > $on) {
            return false;
        }

        return ! ($section->effective_to && $section->effective_to->toDateString() < $on);
    }

    /**
     * Is this payment big enough — on its own, or with the year's others — to be withheld from?
     *
     * The thresholds are what §153 mostly *is*, and they are two different questions. A single large payment
     * is withheld from immediately, whatever else the year holds. A stream of small ones is withheld from
     * once the year's total crosses the annual limit, which for services is low enough that a monthly
     * retainer reaches it inside a year. Either being reached is enough; a section with neither set is
     * withheld from always.
     *
     * **The crossing payment is withheld in full, and nothing earlier is revisited.** ERPNext offers the
     * other reading — deduct on the aggregate the moment the limit is passed, catching up on the payments
     * that went out under it. That is a defensible reading of the statute and a bad thing to do here: the
     * earlier payments are settled and their entries posted, so "catching up" means either a tax line on a
     * payment somebody already banked or a deduction larger than the payment it is deducted from. If a
     * company needs the catch-up, one journal entry does it, and a person decides the amount.
     */
    private function thresholdReached(
        WithholdingSection $section,
        Beneficiary $beneficiary,
        Payment $payment,
        float $gross,
        string $on,
    ): bool {
        $perPayment = $section->per_payment_threshold !== null ? (float) $section->per_payment_threshold : null;
        $annual = $section->annual_threshold !== null ? (float) $section->annual_threshold : null;

        if ($perPayment === null && $annual === null) {
            return true;
        }

        if ($perPayment !== null && $gross >= $perPayment) {
            return true;
        }

        if ($annual === null) {
            return false;
        }

        return round($this->paidThisYear($beneficiary, $payment, $on) + $gross, 2) > $annual;
    }

    /**
     * What this beneficiary has already been paid in the tax year this payment falls in.
     *
     * Drafts are excluded because a draft is an intention; the payment being approved is excluded by key and
     * added back by the caller, because at the moment this runs it is still a draft itself and would
     * otherwise be counted twice or not at all depending on the order of two lines in another class.
     *
     * The year is the fiscal year, which is this application's tax year — `ReportPeriod` runs 1 July to 30
     * June and so does the Ordinance's. Falling back to the calendar year when no fiscal year covers the
     * date is the same fallback `ContractorPaymentSummary` makes.
     */
    private function paidThisYear(Beneficiary $beneficiary, Payment $payment, string $on): float
    {
        $year = FiscalYear::query()
            ->whereDate('start_date', '<=', $on)
            ->whereDate('end_date', '>=', $on)
            ->first();

        $from = $year?->start_date?->toDateString() ?? mb_substr($on, 0, 4).'-01-01';
        $to = $year?->end_date?->toDateString() ?? mb_substr($on, 0, 4).'-12-31';

        return round((float) Payment::query()
            ->where('payable_type', ModuleMap::alias(Beneficiary::class))
            ->where('payable_id', $beneficiary->getKey())
            ->whereKeyNot($payment->getKey())
            ->whereIn('status', [Payment::STATUS_APPROVED, Payment::STATUS_EXPORTED, Payment::STATUS_PAID])
            ->whereNotNull('value_date')
            ->whereDate('value_date', '>=', $from)
            ->whereDate('value_date', '<=', $to)
            ->sum('amount'), 2);
    }

    /**
     * Where the withheld money sits until it is remitted.
     *
     * Throws rather than skipping the deduction, and rather than posting to a guessed account. This is the
     * same position `PaymentService::postEntryFor()` takes ten lines away when account 1100 is missing, and
     * for the same reason: a payment that quietly does not withhold is a liability nobody knows about, while
     * a payment that refuses to be approved is a message on a screen.
     */
    private function accountFor(WithholdingSection $section): int
    {
        $id = $section->account_id
            ?: Account::where('code', WithholdingSection::DEFAULT_ACCOUNT_CODE)->value('id');

        if (! $id) {
            throw new RuntimeException(
                "Withholding section {$section->section} has no posting account, and account "
                .WithholdingSection::DEFAULT_ACCOUNT_CODE.' is not in the chart. Give the section an account.'
            );
        }

        return (int) $id;
    }
}
