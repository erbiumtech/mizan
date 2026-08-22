<?php

/*
| Payroll behaviour that a company decides for itself.
|
| Everything here moves money, which is why every switch defaults to the behaviour a
| company already has. docs/hrms-plan.md §5: a behaviour that moves money must not
| change under a company that did not ask for it, and a new company should get the
| correct default.
|
| Read through setting('payroll.x'), the same three-tier resolution
| accounting.require_second_approver uses.
*/

return [

    /*
    | Whether unpaid absence reduces pay.
    |
    | **OFF.** This is the single most consequential default in this file.
    |
    | Today a payslip can say "LOP 14 days" and pay a full month — the attendance
    | columns are printed and ignored. That is indefensible as a document, and phase 3
    | is what fixes it. But the company running this in production docks nothing for
    | unpaid absence, so switching this on for them would be a NEW DEDUCTION rather
    | than a correction, and nobody asked for one.
    |
    | So: off for everybody, and a company turns it on deliberately, having read what
    | it does. When they do, it applies from that month forward — a locked payroll run
    | is closed, and leave approved after sign-off adjusts the next month.
    |
    | Two tests pin the off case (PayslipAttendanceProrationTest,
    | PayslipCalculationSeamTest). They are not obstacles; they are the guarantee that
    | switching this on is the only thing that can change a figure.
    */
    'prorate_on_attendance' => env('PAYROLL_PRORATE_ON_ATTENDANCE', false),

    /*
    | What pro-rating divides by: working_days | calendar_days | fixed_26 | fixed_30.
    |
    | `working_days` — from the work pattern, which is the number `attendance` can
    | actually produce, and the only one that is right for a company whose month has
    | 21 working days rather than 22.
    |
    | `fixed_26` and `fixed_30` are the conventional divisors much of this market
    | uses, and they are offered rather than argued with.
    |
    | Whichever is chosen is RECORDED ON THE PAYSLIP. Changing this setting governs
    | months calculated afterwards; it never restates one already settled.
    */
    'proration_divisor' => env('PAYROLL_PRORATION_DIVISOR', 'working_days'),

    /*
    | Whether recorded overtime reaches pay (phase 3a).
    |
    | **OFF**, and independent of pro-rating: overtime *adds* pay where pro-rating
    | *removes* it, so neither blocks the other and a company may want one without
    | the other.
    |
    | Until this is on, `attendance_days.overtime_minutes` is a recorded fact that
    | does not reach money — which is the honest state, and far better than a number
    | that reaches pay by an undefined route. The rate is derived from the package and
    | the work pattern, multiplied by attendance.overtime_multiplier, and recorded on
    | the payslip so a later package change cannot restate it.
    */
    'pay_overtime' => env('PAYROLL_PAY_OVERTIME', false),

];
