<?php

namespace App\Support\Banking;

/**
 * The iPayments file layout: 204 comma-delimited columns, and where each value goes.
 *
 * This is a *file format*, not payroll and not accounting. It lived inside
 * `Payroll\Services\SalaryBankExportService`, and the consequence was the one import in this codebase
 * that could not be guarded, degraded or inverted:
 * `Accounting\Services\BankPaymentExportService **extends** Payroll\Services\SalaryBankExportService`.
 * A licence check can wrap a call and a container binding can be swapped at runtime; an `extends` across
 * a module boundary can do neither, so it had to be broken by moving what was actually shared. See
 * docs/module-packaging-plan.md §7.
 *
 * In `app/Support` because both modules need it and neither owns it — and because a bank's column layout
 * is exactly the kind of thing that should not force one module to depend on another. It imports nothing
 * from any module, which is what `ModuleBoundaryTest::test_shared_namespaces_do_not_reach_into_modules`
 * requires of anything living here.
 *
 * A writer rather than a trait, deliberately: a trait would have kept the layout copied into both
 * services at compile time and left no single place to test it, while this can be handed a different
 * column map the day a second bank wants one.
 */
class IPaymentsFileWriter
{
    public const COLUMNS = 204;

    /** 1-indexed column positions from the template's label row. */
    public const COL = [
        'record_type' => 1,
        'payment_type' => 2,
        'processing_mode' => 3,
        'customer_reference' => 5,
        'debit_country' => 7,
        'debit_city' => 8,
        'debit_account' => 9,
        'value_date' => 10,
        'beneficiary_name' => 11,
        'payee_address_1' => 12,
        'payee_address_2' => 13,
        'payee_country' => 14,
        'beneficiary_bank_code' => 16,
        'beneficiary_account' => 20,
        'payment_details_1' => 21,
        'payment_details_2' => 22,
        'invoice_format' => 37,
        'payment_currency' => 38,
        'amount' => 39,
        'debit_currency' => 60,
        'debit_bank_id' => 61,
        'beneficiary_email' => 63,
        'beneficiary_bank_name' => 66,
        'purpose_of_payment' => 166,
        'beneficiary_id' => 167,
        'beneficiary_id_type' => 168,
        'beneficiary_contact' => 204,
    ];

    /**
     * One 204-column row from a map of column-name => value.
     *
     * @param  array<string, mixed>  $values
     */
    public function row(array $values): string
    {
        $cells = array_fill(0, self::COLUMNS, '');

        foreach ($values as $key => $value) {
            $cells[self::COL[$key] - 1] = $this->escape((string) $value);
        }

        return implode(',', $cells);
    }

    /**
     * The amount as the file wants it: two decimals, and no decimals at all when they are both nought.
     */
    public function formatAmount(float $amount): string
    {
        $formatted = number_format($amount, 2, '.', '');

        return str_ends_with($formatted, '.00') ? substr($formatted, 0, -3) : $formatted;
    }

    /**
     * iPayments files are plain comma-delimited; strip characters that would break the layout rather
     * than quoting them.
     */
    public function escape(string $value): string
    {
        return trim(str_replace([',', '"', "\r", "\n"], ' ', $value));
    }
}
