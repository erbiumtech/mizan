<?php

namespace App\Support;

use Closure;

/**
 * Payments a module raises for a month, on request.
 *
 * The bank payment file lists everything outstanding, salaries included, and it *creates* the salary rows
 * as a side effect of being opened — `generateSalaryPayments()` is idempotent on `payslip_id`, so opening
 * the page is what brings a new month's payroll into the payables list. That behaviour is relied on by
 * both bank-file pages and asserted by `PaymentBatchTest`.
 *
 * It also meant an Accounting page calling a service that reads payslips, which is `accounting -> payroll`
 * — and `docs/module-packaging-plan.md` §8 Group C's answer, moving the method into Payroll, only inverts
 * the edge rather than removing it: the *caller* is in Accounting. So the caller asks instead, and Payroll
 * registers what it can raise.
 *
 * A generator is asked for one month of one fiscal year and returns how many rows it created. Nothing
 * registered means nothing raised — which is the correct behaviour for a company that has no payroll, and
 * strictly better than the previous arrangement, where an unlicensed Payroll still had its salary
 * generator called on every view of the page.
 */
class PaymentGenerators
{
    /**
     * @var array<string, Closure(string, mixed): int>
     */
    private static array $generators = [];

    /**
     * @param  string  $key  the transaction-type code this generator raises, e.g. `salary`
     * @param  Closure(string $month, mixed $fiscalYear): int  $generator  returns the number of rows raised
     */
    public static function register(string $key, Closure $generator): void
    {
        self::$generators[$key] = $generator;
    }

    /**
     * Raise everything due for a month, or only one kind of thing.
     *
     * `$only` is the transaction-type filter the page already has: with a type chosen, generating the
     * others would create rows the user cannot see, which is how a filter turns into a side effect.
     */
    public static function raise(string $month, mixed $fiscalYear, ?string $only = null): int
    {
        $raised = 0;

        foreach (self::$generators as $key => $generator) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            $raised += $generator($month, $fiscalYear);
        }

        return $raised;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::$generators);
    }

    public static function flush(): void
    {
        self::$generators = [];
    }
}
