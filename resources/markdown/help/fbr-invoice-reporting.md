This page answers one question: **is there any invoice the books and FBR disagree about?**

It is not a submission screen. Nothing on it sends anything.

## Why it exists

The FBR Tax File under Payroll & tax produces a file somebody downloads and uploads. If nobody downloads it, nothing has happened and it is obvious that nothing has happened.

Invoice reporting works the other way round: the application reports each sales-tax invoice on its own. A submission that never lands looks exactly like one that did from every other screen — the invoice is there, the ledger balances, the customer gets their copy. This page is the only place that failure is visible.

## What each group means

**Refused by FBR.** FBR answered, and said no. The invoice exists in the books and does not exist to FBR. Something on it was wrong; fix that and resubmit.

**Submitted, never answered.** Sent, and still waiting long after an answer was due. These may or may not have reached FBR, which is why they are not simply resent — sending the same invoice twice creates a duplicate at FBR, and removing a duplicate needs approval from the Commissioner.

**Accepted with no reference number.** Marked accepted but carrying no IRN, so nothing can be verified against FBR. Treat as unreported.

**Issued but never reported.** Live invoices this company should have reported and did not. This is the group that carries the compliance exposure.

An empty page means there is nothing to fix. Groups with nothing in them are not shown, so anything you can see is something to act on.

## Reporting on or off

Whether reporting is on is a company setting, and it is off until somebody turns it on.

Turn it on only once the company is registered with FBR and its integrator testing is finished. Turning it on early does not make the company compliant; it only starts reporting the "issued but never reported" gap.

**Whether reporting is required at all is not a question this screen can answer.** It depends on sales-tax registration and turnover, both of which sit outside this application. Ask the company's tax advisor.

## Why an invoice sometimes cannot be voided

Once FBR has accepted an invoice, it may only be cancelled inside FBR's own system, and only for a limited time after it was accepted. After that a correction needs the prior approval of the Commissioner Inland Revenue.

So voiding is refused for a reported invoice, and what to do instead depends on when you are:

- **Still inside the window** — cancel it in the FBR system first. Once FBR shows it cancelled, void it here and the posting reverses as usual.
- **Past the window** — it cannot be cancelled. Use **Credit** on the invoice instead.

The error message on the invoice tells you which case you are in, and when the window closes.

## Credit notes are reported too

A credit note is its own document to FBR, not an amendment to the invoice. So an invoice you reported correctly, then corrected in the books, leaves a gap until the credit note is reported as well — FBR still holds the original figure, and the return will not agree with the ledger.

Credit notes therefore appear in **Issued but never reported** alongside invoices. They are not a way around reporting; they are a second thing to report.

## The two deadlines, and which Commissioner does what

These are easy to confuse, because the Commissioner Inland Revenue appears in both and does something different each time.

**72 hours — changing the invoice.** An electronic invoice can only be cancelled, deleted or edited inside the FBR system, within 72 hours of acceptance. After that, changing the invoice itself needs the Commissioner's prior approval. **This application cannot do that**, which is why Void is refused rather than offered — it will not produce a locally-voided invoice that FBR still considers live.

**180 days — crediting the invoice.** A credit note is not an amendment of the invoice; it is a second document that adjusts the tax, on defined grounds (cancellation of supply, goods returned, a change in the nature or value of the supply). So it needs no approval, and it stays available exactly when the 72 hours are gone. What it needs is to be within **180 days of the supply**. Past that the Commissioner can extend the period once, by a further 180 days, on written request — and the Credit form will ask you for that reference.

So the short version: **past 72 hours you cannot change the invoice, but you can credit it — for 180 days from the supply, then once more with an extension.**

**These are figures set by notification, and they change.** Both are settings a company can override. Have the company's tax advisor confirm them before relying on them, and confirm whether a credit note has to be transmitted to FBR to be effective — this application cannot transmit anything yet, which is why issued credit notes show in the gap list above.
