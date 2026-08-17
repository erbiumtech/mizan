<?php

namespace App\Support\Banking;

use App\Modules\Core\Models\FiscalYear;
use Carbon\Carbon;

/**
 * Which calendar year a named month falls in, inside a given fiscal year.
 *
 * "July" is 2026 in a year running 1 July 2026 to 30 June 2027; "January" is 2027. That is the whole of
 * it — arithmetic on two dates, with no payroll anywhere in it — and it lived on
 * `Payroll\Services\SalaryBankExportService`, which is why `Accounting\Filament\Pages\BankPaymentFile`
 * imported a payroll service to label a file. §7's `PayrollMonth`, and the last of that section's
 * leftovers.
 *
 * `SalaryBankExportService::yearForMonth()` stays as a passthrough: it is public, it is called from
 * templates and tests, and breaking those to move four lines would be a poor trade.
 */
class PayrollMonth
{
    public static function yearFor(string $month, FiscalYear $fiscalYear): int
    {
        $startYear = Carbon::parse($fiscalYear->start_date)->year;
        $monthNumber = Carbon::parse("{$month} 1, {$startYear}")->month;

        return $monthNumber >= Carbon::parse($fiscalYear->start_date)->month
            ? $startYear
            : Carbon::parse($fiscalYear->end_date)->year;
    }
}
