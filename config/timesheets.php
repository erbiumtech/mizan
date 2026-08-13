<?php

/*
| Timesheets: the installation defaults.
|
| Rates are rows, not settings — a project rate lives on the project and an employee
| rate on the employee, because they differ per project and per person. Only the
| last-resort fallback is here, for a company that bills one rate for everybody.
*/

return [

    /*
    | The rate used when neither the project nor the employee names one.
    |
    | Null rather than a number: a made-up rate would produce an invoice that looks
    | right and bills the wrong amount, which is worse than a line that refuses to be
    | built until somebody says what an hour costs.
    */
    'default_hourly_rate' => env('TIMESHEETS_DEFAULT_HOURLY_RATE'),

    /*
    | Whether an entry must be approved before it can be billed.
    |
    | On. Billing a client for time nobody checked is how a disputed invoice starts,
    | and unlike an internal figure it goes out of the building.
    */
    'require_approval_to_bill' => env('TIMESHEETS_REQUIRE_APPROVAL_TO_BILL', true),

];
