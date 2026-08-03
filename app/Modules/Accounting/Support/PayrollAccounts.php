<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use RuntimeException;

/**
 * The company's payroll account mapping — which account each part of a payslip
 * is booked to, from Company Settings with the shipped defaults behind it.
 *
 * Here rather than in Payroll because paying a payslip needs it too: the payslip
 * credits Salaries Payable and the payment has to debit the same account to clear
 * it. Two copies of the resolution would be two chances for them to disagree, and
 * a payment that debits a different account than the payslip credited leaves the
 * liability standing for ever.
 */
class PayrollAccounts
{
    public static function code(string $key): string
    {
        $code = data_get(setting('accounting.payroll_accounts'), $key);

        // A blank or zero code means the mapping was saved without this line
        // rather than deliberately pointing at account "0", so fall back to the
        // shipped default instead of failing.
        if ($code === null || $code === '' || $code === 0 || $code === '0') {
            $code = config('accounting.payroll_accounts.'.$key);
        }

        if ($code === null || $code === '') {
            throw new RuntimeException(
                "Payroll account '{$key}' has no account code configured. Set it under "
                .'Company Settings → Payroll → Payroll Account Codes.'
            );
        }

        return (string) $code;
    }

    public static function id(string $key): int
    {
        $code = static::code($key);

        $account = Account::where('code', $code)->first();

        if (! $account) {
            throw new RuntimeException(
                "Payroll account '{$key}' points at account code {$code}, which does not exist in this "
                .'company\'s chart of accounts. Either correct it under Company Settings → Payroll → '
                .'Payroll Account Codes, or seed the chart with ChartOfAccountsSeeder.'
            );
        }

        return $account->id;
    }
}
