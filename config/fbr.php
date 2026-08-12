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
     * How long a submission may sit in `pending` or `submitted` before the
     * reconciliation report calls it stuck.
     *
     * Not a regulatory figure — an operational one. A submission in flight for a
     * day is not in flight, it is lost, and the whole risk of a push integration
     * is that a lost one looks exactly like a working system.
     */
    'stale_submission_hours' => 24,

];
