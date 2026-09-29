<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bank-file page is about to list a month's payables — raise what your module owes it.
 *
 * Replaces the PaymentGenerators registry, which carried one registrant for its whole
 * life: Payroll, raising the month's salary payments. The registry existed to remove the
 * last `accounting -> payroll` edge — the caller asks, each module answers for itself
 * (docs/module-packaging-plan.md §8 Group C) — and an event keeps exactly that property
 * with machinery Laravel already ships.
 *
 * `$only` is the page's transaction-type filter. With a type chosen, raising the others
 * would create rows the user cannot see, which is how a filter turns into a side effect —
 * so a listener raising type X returns without writing unless $only is null or X.
 */
class RaisingMonthlyPayments
{
    use Dispatchable;

    public function __construct(
        public readonly string $month,
        public readonly mixed $fiscalYear,
        public readonly ?string $only = null,
    ) {}
}
