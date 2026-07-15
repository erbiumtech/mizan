<?php

return [

    /*
    | When true, payroll journal entries are auto-approved and posted on
    | creation. When false they are created as pending_approval and a
    | Manager/CEO must approve and post them.
    */
    'auto_post_payroll' => env('ACCOUNTING_AUTO_POST_PAYROLL', false),

    /*
    | Account codes used by payroll posting (must exist in the chart of
    | accounts — see ChartOfAccountsSeeder).
    */
    'payroll_accounts' => [
        'basic_wage' => '5100',
        'medical_allowance' => '5200',
        'petrol_allowance' => '5300',
        'device_allowance' => '5400',
        'bonus_overtime' => '5500',
        'meal_recovery' => '5600',
        'tax_payable' => '2100',
        'esi_payable' => '2200',
        'salaries_payable' => '2300',
        'employee_advances' => '1200',
    ],
];
