<?php

namespace App\Support;

use Closure;

/**
 * The currency this company's amounts are in — what a money column, a form prefix or a document total
 * should say when nothing more specific (an invoice's own currency, a contract's) applies.
 *
 * It used to be the literal "PKR" in some two hundred places, and two of them read a `currency_code`
 * attribute the company row has never had, so they printed PKR too. The answer belongs to Accounting —
 * the base currency is a row in its currencies table, chosen on Company Settings → Money → Currency — and
 * Accounting registers it here, so a module that only needs to *print* a currency does not have to import
 * Accounting to ask (the module graph stays acyclic). With no resolver registered the installation's
 * home currency is the answer, which is also what a company that has never chosen one keeps its books in.
 */
final class CompanyCurrency
{
    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function code(): string
    {
        $code = self::$resolver !== null ? (string) (self::$resolver)() : '';

        return $code !== '' ? strtoupper($code) : strtoupper((string) config('saas.home_currency', 'PKR'));
    }
}
