<?php

/*
| Attendance policy: the installation defaults.
|
| Read through setting('attendance.x'), which returns the tenant's override when it
| has one and falls back here when it does not — the same three-tier resolution
| accounting.require_second_approver uses.
|
| Work patterns, which weekdays are worked and how long a day is are NOT here. They
| are rows in `work_patterns`, edited by HR, because a company runs more than one and
| the answer differs per employee. See docs/hrms-plan.md §4.7 on the three tiers.
*/

return [

    /*
    | How long daily rows are kept before pruning.
    |
    | This is the only unbounded table in the HR schema — it grows with usage rather
    | than with headcount — so it is Prunable from day one rather than after a
    | five-year-old tenant is carrying 50k rows nobody reads.
    |
    | Three years, because a payroll dispute or a labour-court question reaches back
    | further than one, and further than that nobody has ever asked.
    */
    'retention_months' => env('ATTENDANCE_RETENTION_MONTHS', 36),

    /*
    | What an hour of overtime is worth, as a multiple of the ordinary hourly rate.
    |
    | 2.0, because the Factories Act mandates double the ordinary rate. Other
    | establishments differ by province, which is the same reason every statutory
    | figure in config/statutory.php is configurable rather than encoded — this
    | application should not pretend to more certainty about labour law than it has.
    |
    | Only read when payroll.pay_overtime is on (phase 3a). Until then overtime is a
    | recorded fact that does not reach pay.
    */
    'overtime_multiplier' => env('ATTENDANCE_OVERTIME_MULTIPLIER', 2.0),

    /*
    | Overtime caps. These WARN; they never reduce the figure.
    |
    | Silently capping paid overtime hides an employer's compliance problem and
    | underpays somebody at the same time — two wrongs from one line of code. The
    | same position §4.2 takes on SLA breaches and §6 on minimum wage: report it,
    | do not quietly adjust it.
    |
    | The daily figure is the Factories Act's two hours; the weekly one is twelve.
    */
    'overtime_daily_cap_minutes' => env('ATTENDANCE_OVERTIME_DAILY_CAP', 120),
    'overtime_weekly_cap_minutes' => env('ATTENDANCE_OVERTIME_WEEKLY_CAP', 720),

    /*
    | Minutes past the pattern's start time before a day counts as late.
    |
    | A grace period, because a system that records a two-minute lateness produces a
    | report nobody reads. Recorded only; nothing in this application docks pay for
    | lateness, and nothing should without somebody asking for it.
    */
    'late_grace_minutes' => env('ATTENDANCE_LATE_GRACE_MINUTES', 15),

    /*
    | How long a compensatory-off credit lasts before it lapses (phase 2a).
    |
    | The one place this plan family admits a lapse date it refused for leave
    | carry-forward, and deliberately: a comp-off earned in March and taken three
    | years later is not time off *in lieu* of anything.
    */
    'comp_off_expiry_days' => env('ATTENDANCE_COMP_OFF_EXPIRY_DAYS', 90),

];
