<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Models\Payment;
use App\Support\ModuleMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Raising the month's standing payments.
 *
 * A draft per subscription, exactly as if somebody had typed it: it goes into the
 * same pool, the same batch and the same bank file, and it is approved and posted
 * on release like everything else. Nothing here touches the ledger.
 */
class SubscriptionBillingService
{
    /**
     * Draft payments for every subscription running in this month.
     *
     * Idempotent: a subscription already billed for the month is left alone
     * rather than raised again, and the unique key on (subscription, period)
     * stands behind that in case two runs overlap.
     *
     * @return Collection<int, Payment> the payments raised by this call
     */
    public function generateFor(Carbon $period, ?Carbon $runningOn = null): Collection
    {
        $period = $period->copy()->startOfMonth();
        $raised = collect();

        foreach ($this->due($period) as $subscription) {
            if ($this->alreadyBilled($subscription, $period)) {
                continue;
            }

            $raised->push($this->raise($subscription, $period, $runningOn));
        }

        return $raised;
    }

    /**
     * @return Collection<int, BeneficiarySubscription>
     */
    public function due(Carbon $period): Collection
    {
        return BeneficiarySubscription::active()
            ->with(['beneficiary', 'transactionType'])
            ->orderBy('due_day')
            ->get()
            ->filter(fn (BeneficiarySubscription $subscription): bool => $subscription->coversPeriod($period))
            ->values();
    }

    public function alreadyBilled(BeneficiarySubscription $subscription, Carbon $period): bool
    {
        return $subscription->payments()
            ->whereDate('period', $period->copy()->startOfMonth()->toDateString())
            ->exists();
    }

    protected function raise(BeneficiarySubscription $subscription, Carbon $period, ?Carbon $runningOn): Payment
    {
        $typeId = $subscription->resolvedTransactionTypeId();

        if (! $typeId) {
            // Refused rather than raised unbookable: a payment whose type has no
            // account cannot be approved, and one with no type at all cannot even
            // be told what it was for.
            throw new RuntimeException(
                "Subscription \"{$subscription->description}\" has no transaction type, and neither does "
                .($subscription->beneficiary?->name ?? 'its beneficiary').'.'
            );
        }

        return Payment::create([
            // The stable alias, not the live class: payable_type holds a class name
            // across the module move.
            'payable_type' => ModuleMap::alias(Beneficiary::class),
            'payable_id' => $subscription->beneficiary_id,
            'transaction_type_id' => $typeId,
            'company_bank_account_id' => $subscription->company_bank_account_id
                ?? $subscription->transactionType?->defaultCompanyBankAccount()?->id,
            'beneficiary_subscription_id' => $subscription->getKey(),
            'period' => $period->toDateString(),
            'amount' => $subscription->amount,
            'details' => $subscription->description.' — '.$period->format('F Y'),
            'value_date' => $subscription->valueDateFor($period, $runningOn)->toDateString(),
            'status' => Payment::STATUS_DRAFT,
        ]);
    }
}
