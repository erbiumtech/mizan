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
    'increment_scale' => [
        5 => 0.15,
        4 => 0.10,
        3 => 0.05,
        2 => 0.0,
        1 => 0.0,
    ],

];
