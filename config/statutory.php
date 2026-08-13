<?php

/*
| Statutory Pakistan figures — phase 9, and the settlement conventions phase 6 needs.
|
| **Every number here is a DEFAULT that a company's HR must confirm, not a statement of
| law.** Rates, ceilings and entitlements differ by province — Sindh, Punjab, KP and
| Balochistan each legislate their own — and they change with each provincial budget.
| This application already takes that position on tax slabs ("a Finance Act change is a
| re-seed"), and it should not pretend to more certainty about labour law than it has.
|
| A wrong contribution rate applied confidently is worse than a blank one that asks.
| That is why nothing here is applied automatically: the figures produce pay components
| a person reviews, never a deduction that appears on its own.
|
| Keyed by scheme, and by province where the scheme is provincial.
*/

return [

    /*
    | Gratuity: conventionally one month's wage per completed year of service, payable
    | on separation after a qualifying period.
    |
    | Read by the final settlement builder. Entitlement and formula vary by
    | establishment, which is why both the multiplier and the qualifying period are
    | settings rather than constants.
    */
    'gratuity' => [
        'months_per_year' => env('STATUTORY_GRATUITY_MONTHS_PER_YEAR', 1.0),
        'minimum_years' => env('STATUTORY_GRATUITY_MINIMUM_YEARS', 1),
    ],

    /*
    | What a day of encashed leave is worth: the basic wage over this many days.
    |
    | 26 is the conventional divisor in this market. Deliberately NOT the attendance
    | pro-rating divisor: encashment is a lump sum against a package, and borrowing the
    | month's working days would make the payout depend on which month somebody left in.
    */
    'encashment_divisor' => env('STATUTORY_ENCASHMENT_DIVISOR', 26),

    /*
    | EOBI — the federal old-age benefits scheme.
    |
    | Contributions are a percentage of the *minimum wage* rather than of actual pay,
    | which is what makes it a fixed rupee amount per employee per month rather than a
    | percentage of salary. Both the rate and the wage it applies to move; confirm both.
    */
    'eobi' => [
        'employee_rate' => env('STATUTORY_EOBI_EMPLOYEE_RATE', 0.01),
        'employer_rate' => env('STATUTORY_EOBI_EMPLOYER_RATE', 0.05),
        'wage_basis' => env('STATUTORY_EOBI_WAGE_BASIS', 37000),
    ],

    /*
    | Provincial social security (SESSI, PESSI and their equivalents).
    |
    | An employer contribution up to a wage ceiling. Both differ by province, so the
    | table is keyed by one — and the key a company uses is its own setting, because
    | this application does not know where a company operates.
    */
    'social_security' => [
        'default_province' => env('STATUTORY_PROVINCE', 'sindh'),

        'sindh' => ['employer_rate' => 0.06, 'wage_ceiling' => 25000],
        'punjab' => ['employer_rate' => 0.06, 'wage_ceiling' => 25000],
        'kp' => ['employer_rate' => 0.06, 'wage_ceiling' => 25000],
        'balochistan' => ['employer_rate' => 0.06, 'wage_ceiling' => 25000],
        'islamabad' => ['employer_rate' => 0.06, 'wage_ceiling' => 25000],
    ],

    /*
    | Provident fund: an employee percentage with an employer match.
    |
    | Voluntary for most establishments, which is why it defaults to off. When on, both
    | sides are pay components and the employer side accrues as a liability.
    */
    'provident_fund' => [
        'enabled' => env('STATUTORY_PF_ENABLED', false),
        'employee_rate' => env('STATUTORY_PF_EMPLOYEE_RATE', 0.0833),
        'employer_rate' => env('STATUTORY_PF_EMPLOYER_RATE', 0.0833),
    ],

    /*
    | Minimum wage: a floor to WARN against, per province and per year.
    |
    | Never a silent adjustment. Paying below the minimum is the employer's problem to
    | see and fix; an application that quietly raised the figure would hide a compliance
    | breach and misstate the agreed package at the same time.
    */
    'minimum_wage' => [
        'sindh' => env('STATUTORY_MINIMUM_WAGE_SINDH', 37000),
        'punjab' => env('STATUTORY_MINIMUM_WAGE_PUNJAB', 37000),
        'kp' => env('STATUTORY_MINIMUM_WAGE_KP', 36000),
        'balochistan' => env('STATUTORY_MINIMUM_WAGE_BALOCHISTAN', 37000),
        'islamabad' => env('STATUTORY_MINIMUM_WAGE_ISLAMABAD', 37000),
    ],

];
