<?php

/**
 * FBR digital invoicing: the rules, as configuration rather than as constants.
 *
 * Every figure here is set by notification and has changed more than once in a
 * year — SRO 709(I)/2025, then SRO 1413(I)/2025, then reportedly SRO
 * 1852(I)/2025, with Sales Tax General Order 01 of 2026 clarifying the amendment
 * rules. That rate of change is exactly why these are config keys a company can
 * override through TenantSettings, the same argument config/statutory.php makes
 * for tax rates: an amendment should be a settings change, not a deploy.
 *
 * See docs/fbr-digital-invoicing-plan.md, whose §9 lists what still has to be
 * confirmed with a tax advisor before any of this transmits anything.
 */
return [

    /**
     * Is this company reporting invoices to FBR?
     *
     * OFF by default, and deliberately a setting rather than a module licence: a
     * disabled module is meant to be safe to disable, and switching off
     * statutory reporting is not safe for a company above the turnover
     * threshold — they would carry on issuing invoices, now non-compliant. A
     * licence says "you did not buy this"; compliance is not bought.
     *
     * Turning this on is the last step of the rollout, not the first. Nothing in
     * the application transmits anything yet: there is no integrator driver (see
     * the plan, §3), so this currently only changes what the reconciliation
     * report considers a gap.
     */
    'enabled' => false,

    /**
     * Hours during which a reported invoice may still be cancelled or edited,
     * counted from when FBR accepted it.
     *
     * 72 per STGO 01 of 2026. After it, a correction needs the prior approval of
     * the Commissioner Inland Revenue — which is not something this application
     * can do, and is why InvoiceService::void() refuses rather than offering a
     * button that produces books disagreeing with FBR.
     *
     * NOTE: whether the clock runs from FBR acceptance or from local issuance is
     * open (plan §9.6). This assumes acceptance, which is what `fbr_reported_at`
     * records.
     */
    'correction_window_hours' => 72,

    /**
     * Days from the supply within which a credit note may still adjust output tax.
     *
     * 180, per rule 22 of the Sales Tax Rules 2006. This is a **different rule from
     * the 72 hours above and rests on different law**, which is the distinction that
     * took a while to see clearly:
     *
     *  - the 72 hours is STGO 01 of 2026 and governs amending or cancelling the
     *    e-invoice itself. Past it, changing the invoice needs the Commissioner's
     *    prior approval, and this application refuses rather than pretending;
     *  - these 180 days are section 9 of the Sales Tax Act 1990 with rules 20–22,
     *    and govern the debit/credit note route — cancellation of supply, return of
     *    goods, or a change in the nature or value of the supply. A credit note is
     *    not an amendment of the invoice; it is a second document that adjusts the
     *    tax. That is why it remains available when the 72 hours have gone.
     *
     * So the Commissioner appears twice, doing two different jobs. Past 72 hours
     * they may permit the invoice to be changed. Past 180 days they may extend the
     * credit-note window — see `credit_note_extension_days` below.
     */
    'credit_note_days' => 180,

    /**
     * A further period the Commissioner may allow for a credit note, on the
     * supplier's written request and with reasons recorded.
     *
     * 180 again, per the proviso to rule 22: the Collector "may, at the request of
     * the supplier, in specific cases, by giving reasons in writing, extend the
     * period of one hundred and eighty days by a further one hundred and eighty
     * days". Once, not repeatedly — which is why this is a single further period
     * rather than a multiplier.
     *
     * This is the only place in the application where Commissioner approval is
     * something a company can actually record and act on, as opposed to something
     * it must go and obtain outside the system. It does not grant the extension; it
     * records that one was granted, and refuses beyond it.
     */
    'credit_note_extension_days' => 180,

    /**
     * How long a submission may sit in `pending` or `submitted` before the
     * reconciliation report calls it stuck.
     *
     * Not a regulatory figure — an operational one. A submission in flight for a
     * day is not in flight, it is lost, and the whole risk of a push integration
     * is that a lost one looks exactly like a working system.
     */
    'stale_submission_hours' => 24,

];
