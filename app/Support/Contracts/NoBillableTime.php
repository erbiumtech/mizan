<?php

namespace App\Support\Contracts;

/**
 * No booked time: nothing to bill by the hour, nothing to lock.
 *
 * Bound as the default so a company that bills by headcount gets exactly the invoice it got before the
 * Timesheets module existed — an empty `hours` section that contributes nothing to the subtotal, which is
 * what `MonthlyBillingService`'s `modules()->enabled('timesheets')` guard used to produce and what
 * `BillingTest` already asserts.
 */
class NoBillableTime implements BillableTime
{
    public function linesFor(int|string $contactId, int $year, int $month): array
    {
        return [];
    }

    public function lockFor(int|string $contactId, int $year, int $month): int
    {
        return 0;
    }
}
