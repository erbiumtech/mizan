<?php

namespace App\Modules\Advances\Services;

use App\Modules\Advances\Models\Advance;
use App\Modules\Core\Models\User;
use App\Notifications\RecordChanged;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * An advance as something applied for — docs/saas-launch-plan.md §14.3: requested,
 * then approved into recovery or refused with a reason; paused and resumed while it runs.
 */
class AdvanceApplications
{
    public function approve(Advance $advance, User $by, string|Carbon|null $givenOn = null): Advance
    {
        if ($advance->status !== Advance::STATUS_REQUESTED) {
            throw new InvalidArgumentException("This advance is {$advance->status}, not requested.");
        }

        $advance->type?->check((float) $advance->total_amount, $advance->instalments, (int) $advance->employee_id, $advance->getKey());

        $advance->update([
            'status' => Advance::STATUS_ACTIVE,
            'started_on' => Carbon::parse($givenOn ?? now())->toDateString(),
            'decided_by' => $by->getKey(),
            'decided_at' => now(),
            'refusal_reason' => null,
        ]);

        $advance->employee?->user?->notify(new RecordChanged('Your advance was approved', number_format((float) $advance->total_amount).' recovered at '.number_format((float) $advance->monthly_instalment).' a month.'));

        return $advance->refresh();
    }

    public function refuse(Advance $advance, User $by, string $reason): Advance
    {
        if ($advance->status !== Advance::STATUS_REQUESTED) {
            throw new InvalidArgumentException("This advance is {$advance->status}, not requested.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal needs a reason.');
        }

        $advance->update(['status' => Advance::STATUS_REFUSED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'refusal_reason' => trim($reason)]);
        $advance->employee?->user?->notify(new RecordChanged('Your advance was not approved', trim($reason)));

        return $advance->refresh();
    }

    /** Nothing is deducted while paused and nothing is written off; the advance runs longer. */
    public function pause(Advance $advance, string $reason): Advance
    {
        if ($advance->status !== Advance::STATUS_ACTIVE) {
            throw new InvalidArgumentException("Only an active advance can be paused; this one is {$advance->status}.");
        }

        $advance->update(['status' => Advance::STATUS_PAUSED, 'paused_at' => now(), 'paused_reason' => trim($reason) ?: null]);

        return $advance->refresh();
    }

    public function resume(Advance $advance): Advance
    {
        if ($advance->status !== Advance::STATUS_PAUSED) {
            throw new InvalidArgumentException('This advance is not paused.');
        }

        $advance->update(['status' => Advance::STATUS_ACTIVE, 'paused_at' => null, 'paused_reason' => null]);

        return $advance->refresh();
    }
}
