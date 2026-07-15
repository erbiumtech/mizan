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
    'processing_mode' => env('IPAYMENTS_PROCESSING_MODE', 'ON'),
    'invoice_format' => env('IPAYMENTS_INVOICE_FORMAT', '4'),
    'purpose_of_payment' => env('IPAYMENTS_PURPOSE_CODE', '104'),
];
