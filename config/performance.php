<?php

/*
| Appraisals.
|
| One key, and it is a SUGGESTION scale rather than a policy.
*/

return [

    /*
    | Rating to suggested increment, as a fraction.
    |
    | **Nothing applies this.** ReviewCycleService::suggestedIncrement() returns a figure
    | for a human to consider; saving a new package is a separate, deliberate act.
    |
    | It lives in config rather than code precisely so nobody mistakes it for a policy this
    | application holds an opinion about — every company's scale is its own, and most have
    | none at all.
    |
    | docs/hrms-plan.md §4.5: a rating is an opinion, a package is approved. Wiring one to
    | the other would make the appraisal a payroll instruction.
    */
    /*
    | The merit ledger — docs/saas-launch-plan.md §8.1. Points per source, each a
    | company setting under performance.merit.* with these as the defaults.
    | `late_mark_points` is zero as shipped: a late mark becomes evidence only
    | when a company says so.
    */
    'merit' => [
        'late_mark_points' => 0,
        // What accepting a daily-report verdict writes, by verdict — §8.3. The
        // model never writes these; a manager's accept does.
        'report_points' => [
            'substantive' => 2,
            'thin' => 0,
            'off_topic' => -1,
        ],
    ],

    /*
    | §8.2's rupee penalty. Recorded on each task and summed by tasks:settle for
    | the payroll clerk; nothing docks pay by itself. The automatic payslip row
    | the plan described waits for the payroll engine to carry a key for it —
    | see SettleTaskPenalties.
    */
    'penalty_pay_component' => null,

    'increment_scale' => [
        5 => 0.15,
        4 => 0.10,
        3 => 0.05,
        2 => 0.0,
        1 => 0.0,
    ],

];
