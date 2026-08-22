<?php

return [
    /*
     * Three-way match tolerances — `docs/construction-management-plan.md` §5.
     *
     * "Tolerances live in config overridable by settings, the `PayrollAccounts` pattern." So these are the shipped
     * defaults and a company sets its own under Company Settings; `App\Modules\ConstructionCosting\Support\
     * MatchTolerances` resolves the setting first and falls back here.
     *
     * **A tolerance is not a rounding allowance, and the two figures below are different questions.** A quantity
     * variance says the delivery did not match the order — 39.6 tonnes against 40 is a short delivery somebody
     * accepted on the day. A price variance says the invoice did not match the order, which is a different
     * conversation with a different person: the buyer agreed a rate and the supplier billed another.
     *
     * Zero would be wrong for both. A contract that tolerates nothing puts every rounded weight ticket in front of a
     * human, and a control that fires on everything is a control people learn to click through.
     */
    'match' => [
        // Percentage of the ordered quantity a delivery may differ by before it needs somebody's name on it.
        'quantity_percent' => 2.0,

        // Percentage of the ordered value an invoice may differ by. Tighter than quantity on purpose: a short
        // delivery is a fact about a lorry, and a price difference is a fact about an agreement.
        'price_percent' => 1.0,

        /*
         * An absolute floor, in the company's own currency, under which a variance is not worth anybody's time
         * whatever the percentages say.
         *
         * Without it, a 2% tolerance on a 500 order flags a 10 difference — and a control that fires on a 10
         * difference is a control that trains people to accept without reading.
         */
        'minimum_amount' => 1_000.0,
    ],

    /*
     * Labour terms a rate row may leave unstated — `docs/construction-management-plan.md` §7.
     *
     * `overtime_multiplier` and `burden_percent` are nullable on `construction_labour_rates` so a row can revise the
     * rate without restating terms the company set once (§7.2's ladder resolves each field independently). These are
     * the last resort when no row in the ladder states them at all.
     *
     * **There is deliberately no default cost rate here**, and that asymmetry is the point: an overtime multiplier and
     * a burden percentage are company policy, while what an hour costs is a fact about a wage. `LabourRateService::
     * resolve()` returns null when nothing sets the rate, and the caller refuses — because a labour cost of 0.00 on a
     * full week is §18.1's healthy-looking figure hiding an absence.
     */
    'labour' => [
        // Time and a half, which is the commonest statutory and contractual position. A company that pays double
        // time sets its own, either here or on a rate row.
        'overtime_multiplier' => 1.5,

        /*
         * Zero, and this one is a considered default rather than a placeholder.
         *
         * Burden is only ever correct as a figure a company has worked out from its own statutory and welfare cost,
         * and §7.3's rule is that whatever is charged to jobs must be *absorbed* against a real account or both
         * ledgers diverge by exactly the burden, growing monthly, with no error anywhere. A shipped guess would
         * start that divergence on day one for a company that never chose it; zero charges nothing and absorbs
         * nothing, which is the only self-consistent starting point.
         */
        'burden_percent' => 0.0,
    ],

    /*
     * Quality, health, safety and environment — `docs/construction-management-plan.md` §17.
     */
    'qhse' => [
        /*
         * **How long after an incident a report is still timely.**
         *
         * §17.3 makes the reporting delay a safety metric in its own right: "a site that takes four days to report a
         * first-aid case is a site where the next one is not reported at all." What counts as late is *policy* rather
         * than fact — a company whose procedure says two hours is not measuring the same thing as one that says a shift
         * — so the threshold is configuration and the register reports the delay itself either way.
         *
         * Twenty-four hours is the commonest procedural position and the one most statutory regimes assume.
         */
        'report_within_hours' => 24,
    ],

    /*
     * The programme — `docs/construction-management-plan.md` §13.
     */
    'programme' => [
        /*
         * **How many hours make a working day**, which is the only unit conversion an imported programme needs.
         *
         * P6 counts durations, total float and relationship lag in *hours* against an activity's calendar; MS Project
         * counts slack in tenths of a minute. Both have to become days to be readable beside a date, and nothing in
         * either file says how long the working day is.
         *
         * Eight is the near-universal default. It is configuration rather than a constant because a job on a ten-hour
         * shift would have every float figure overstated by a quarter — and float is what a delay argument turns on, so
         * a quarter is not a rounding matter.
         */
        'hours_per_day' => 8,
    ],

    /*
     * Delay events — `docs/construction-management-plan.md` §13.
     *
     * `notice_required_by` is `occurred_on + contract notice days`. A contract states its own period in
     * `construction_contracts.delay_notice_days`; this is what applies when it does not, or when the event names no
     * contract at all — which is the ordinary case for a job whose commercial side is kept elsewhere.
     */
    'delay' => [
        /*
         * **28 days, which is FIDIC 20.1** — the commonest position by a wide margin, and the one a contractor running
         * an international form will recognise. NEC4's compensation-event clock is eight weeks and a bespoke
         * subcontract is often seven days, which is exactly why the contract's own column overrides this.
         *
         * There is deliberately no "no notice required" option here: a period of zero would make every event
         * time-barred on the day it happened, and a null would leave the clock — the one thing §13 says is worth more
         * than the whole programme — silently switched off.
         */
        'notice_days' => 28,

        /*
         * How long after the notice the detailed particulars are due. FIDIC 20.1 gives 42 days from the event; this is
         * measured from the notice instead, because that is the date the contractor controls and can therefore plan
         * against.
         */
        'particulars_days' => 42,
    ],
];
