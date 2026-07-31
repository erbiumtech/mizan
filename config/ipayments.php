<?php

// Standard Chartered iPayments bulk salary file defaults.
// Template: docs/ipayments_csv (002).csv (204 comma-delimited columns, UTF-8).
return [
    'debit_account' => env('IPAYMENTS_DEBIT_ACCOUNT', ''),
    'debit_bank_id' => env('IPAYMENTS_DEBIT_BANK_ID', 'SCBLPKKXXXX'),
    'debit_country' => env('IPAYMENTS_DEBIT_COUNTRY', 'PK'),
    'debit_city' => env('IPAYMENTS_DEBIT_CITY', 'KHI'),
    'currency' => env('IPAYMENTS_CURRENCY', 'PKR'),
    'payment_type' => env('IPAYMENTS_PAYMENT_TYPE', 'IBFT'),

    /*
    | Salary transfers to employees carry their own payment type — the bank
    | treats bulk payroll differently from an ordinary inter-bank transfer.
    | Applied to every row of the Salary Bank File, and to any payment in the
    | Bank Payment File that settles a payslip. See Payment::resolvedPaymentType.
    */
    'salary_payment_type' => env('IPAYMENTS_SALARY_PAYMENT_TYPE', 'PAY'),
    'processing_mode' => env('IPAYMENTS_PROCESSING_MODE', 'ON'),
    'invoice_format' => env('IPAYMENTS_INVOICE_FORMAT', '4'),
    'purpose_of_payment' => env('IPAYMENTS_PURPOSE_CODE', '104'),

    /*
     * How to recognise a beneficiary who banks with the debiting bank itself.
     *
     * Those are intra-bank transfers and the file must carry the plain account
     * number; every other beneficiary is an inter-bank IBFT and needs the IBAN.
     * See App\Support\BankFileAccount.
     *
     * SCB is not in the IBFT bank directory (BankSeeder lists the banks you
     * transfer out to), so the match is attempted on the bank's short code, its
     * name, and the bank identifier inside a Pakistani IBAN — PK36|SCBL|0000…
     */
    'own_bank' => [
        'short_codes' => ['SCB', 'SCBL', 'SCBPL'],
        'name_contains' => env('IPAYMENTS_OWN_BANK_NAME', 'standard chartered'),
        'iban_prefix' => env('IPAYMENTS_OWN_BANK_IBAN_PREFIX', 'SCBL'),
    ],
];
