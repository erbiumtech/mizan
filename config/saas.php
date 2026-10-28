<?php

/**
 * The platform's own billing — docs/saas-launch-plan.md §6.
 *
 * The operator is a tenant: the company that runs this installation keeps its
 * own books in it, so a customer's subscription is an ordinary customer, an
 * ordinary recurring agreement and ordinary invoices in that tenant. Everything
 * here is read by App\Support\Subscriptions and the three `billing:*` commands,
 * and by nothing else.
 */
return [

    // The slug of the operator's own company. Unset means the platform bills
    // nobody: the Subscribe action is absent and the commands exit saying so.
    'operator_company' => env('SAAS_OPERATOR_COMPANY'),

    // The revenue account a subscription line posts to, in the operator's chart.
    // 4100 is "Service Revenue" in the seeded chart.
    'revenue_account_code' => env('SAAS_REVENUE_ACCOUNT', '4100'),

    // Days a customer has to pay an invoice, written onto the agreement.
    'due_days' => 15,

    // Months per invoice by currency. PKR customers pay by IBFT, which is free, so
    // monthly; a SWIFT fee would dominate a monthly USD or EUR invoice, so those are
    // quarterly in advance until a card gateway exists.
    'cadence_months' => ['PKR' => 1, 'USD' => 3, 'EUR' => 3],

    // The currency a billing address in Pakistan is billed in, and nothing else.
    'home_country' => 'PK',
    'home_currency' => 'PKR',

    // billing:enforce — days past a trial's end before suspension, and days past an
    // invoice's due date before suspension. Dunning before that point is the
    // invoicing module's own reminders, switched on in the operator tenant.
    'grace_days' => 7,
    'overdue_days' => 30,

    // billing:issue holds a draft whose seat count moved by more than this share
    // against the previous invoice, and tells the operator instead of sending it.
    'issue_hold_ratio' => 0.5,

];
