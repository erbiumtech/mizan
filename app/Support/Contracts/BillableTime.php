<?php

namespace App\Support\Contracts;

/**
 * Booked time, priced, for whoever bills by the hour.
 *
 * `billing <-> timesheets` was the smaller of the two cycles left after phase 9, and the only one where
 * *neither* direction was a declared requirement — Billing does not require Timesheets (a headcount-billed
 * client never books an hour) and Timesheets does not require Billing (booked time is worth recording
 * whether or not anyone invoices it). Two modules that need not be sold together were nevertheless
 * unextractable, which is the purest form of the debt this plan exists to pay.
 *
 * The edge went both ways and each direction needed a different answer:
 *
 * - `billing -> timesheets` — `MonthlyBillingService` named `Timesheets\Services\BillableHours` to ask for
 *   the lines. It asks whoever is bound here instead.
 * - `timesheets -> billing` — `BillableHours` took a `BillingRun`, and used exactly three things from it:
 *   the contact, the year and the month. So it takes those. **A contract that passes a model passes the
 *   module that owns it**, and no amount of indirection around it would have removed the edge.
 *
 * `NoBillableTime` is the default: no hours, nothing locked. It is also what an *unlicensed* Timesheets
 * must look like, and that guard travels with the implementation rather than with the caller — the same
 * arrangement as `PayrollRunPeriodLock`, because Billing asking "is timesheets enabled" is Billing knowing
 * about Timesheets by another name.
 */
interface BillableTime
{
    /**
     * Invoice lines for everything billable to one contact in one month.
     *
     * One line per employee per project, priced at the applicable rate. Time that cannot be priced is
     * omitted rather than guessed at — see the implementation for why that is refused loudly.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    public function linesFor(int|string $contactId, int $year, int $month): array;

    /**
     * Lock everything that was billed, so no hour reaches a second invoice, and report how many.
     *
     * Called when an invoice is BUILT and never when a breakdown is previewed: a clerk looking at next
     * month's figures must not burn the hours.
     */
    public function lockFor(int|string $contactId, int $year, int $month): int;
}
