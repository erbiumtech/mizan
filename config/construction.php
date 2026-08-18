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
];
