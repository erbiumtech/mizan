<?php

/*
| Leave policy: the installation defaults.
|
| Every key here is readable through setting('leave.x'), which returns the current
| tenant's override when it has one and falls back to this file when it does not —
| the same three-tier resolution accounting.require_second_approver uses, and the
| one SecondApproverRuleTest already pins. So: an installation default in .env, a
| per-company answer on Company Settings, and the company's answer winning once
| given.
|
| Most of what looks like leave policy is NOT here, on purpose. A company that
| wants 18 annual days edits a leave_types row; day counts, is_paid,
| min_notice_days and is_encashable are reference data HR edits day to day, not
| settings we ship combinations of. docs/hrms-plan.md §4.7 has the three tiers and
| why putting something in the wrong one is the mistake.
|
| Each key below earns its place on three counts: a real company wants the other
| value, the default is defensible without asking, and both directions are tested.
| The half-month boundary in months_remaining fails those counts and deliberately
| stays in code — a company that wanted a different one would be asking for a
| different accrual method, not different rounding.
*/

return [

    /*
    | When everybody's leave year starts: calendar | fiscal | anniversary.
    |
    | `calendar` is not a guess about what most policies say — it is what the one
    | policy we can read says. The company running this in production resets
    | balances on 1 January for everybody, whatever an employee's joining date.
    |
    | `fiscal` follows FBR's July–June year, which employee_settings are already
    | versioned by. `anniversary` is closest to the statutory entitlement, which
    | accrues on completing twelve months of *service* and is therefore a different
    | window per employee.
    |
    | Changing this governs entitlements created afterwards only. The window is
    | stamped on each leave_entitlements row precisely so a change in June cannot
    | restate a year already under way.
    */
    'year_basis' => env('LEAVE_YEAR_BASIS', 'calendar'),

    /*
    | Whether unused days carry into the next leave year, capped per type by
    | leave_types.max_carry_forward.
    |
    | Off, because the pilot resets on 1 January and carries nothing, so their
    | behaviour is the default. Whether days carry is a policy other companies
    | answer differently, and it is theirs to choose rather than ours to impose.
    |
    | Two tiers: this switch is the company policy, and the per-type cap is the
    | limit. A cap of 0 means that type never carries even for a company that does,
    | which is how "annual carries five days, casual carries none" is expressed
    | without a second setting.
    |
    | Expiry of carried days ("they lapse on 31 March") is deliberately NOT built
    | and no toggle claims it is — carried days join the new year's balance and
    | lapse with it at the next reset.
    */
    'carry_forward' => env('LEAVE_CARRY_FORWARD', false),

    /*
    | Whether a mid-year joiner gets a pro-rated first year rather than the full
    | year's days: days_per_year × months_remaining / 12, rounded to the nearest
    | half day so it agrees with the granularity leave_days.portion already uses.
    |
    | On, because pro-rating is the conventional answer and the pilot confirmed it.
    | Off is common enough — a company granting the full year's days from day one —
    | to be worth one boolean.
    */
    'prorate_first_year' => env('LEAVE_PRORATE_FIRST_YEAR', true),

    /*
    | Whether somebody may approve their own leave.
    |
    | On, and it is the same dead end SecondApproverRule was written for in the
    | ledger: a manager filing their own leave routes to *their* manager, and the
    | employee at the top of the tree has nobody. A company with one operator turns
    | this off, and the activity log records each self-approval as one.
    */
    'require_second_approver' => env('LEAVE_REQUIRE_SECOND_APPROVER', true),

    /*
    | Whether leave_types.min_notice_days blocks a request or merely warns.
    |
    | Off: short notice is usually the point of casual leave, and a system that
    | refuses to record leave somebody has already taken helps nobody — the same
    | position §4.2 takes on SLA breaches and overtime caps.
    */
    'min_notice_enforced' => env('LEAVE_MIN_NOTICE_ENFORCED', false),

    /*
    | What a Friday-plus-Monday request consumes when the weekend sits between two
    | leave days: `off` skips non-working days (2 days), `enclosed` consumes them
    | (4 days).
    |
    | Off. The pilot has not asked for it, and a policy that quietly consumes two
    | extra days is the wrong default to impose. The branch ships with the generator
    | rather than later because it changes which leave_days are generated —
    | retrofitting it would mean regenerating days for requests already approved and
    | already on a payslip.
    |
    | `enclosed` is the only variant offered. The stricter reading some policies
    | take — a holiday adjacent to leave consumed even at the edges — makes a single
    | Friday's leave cost three days, which nobody expects, and no policy anybody
    | here has read asks for it.
    */
    'sandwich_rule' => env('LEAVE_SANDWICH_RULE', 'off'),

    /*
    | Which weekdays the company does not work, as ISO-8601 numbers (1 = Monday,
    | 7 = Sunday).
    |
    | A stopgap, and labelled as one. Weekends properly belong to work patterns,
    | which `attendance` owns and which do not exist yet — HolidayCalendar
    | deliberately has no isWorkingDay() for exactly this reason. The leave-day
    | generator cannot avoid the question, so it asks here, and this key goes away
    | when work_patterns land: the generator reads the employee's pattern and falls
    | back to this only when `attendance` is unlicensed.
    |
    | Saturday and Sunday, with a note that six-day weeks are common in this market
    | and such a company sets [7].
    */
    'weekend_days' => [6, 7],

];
