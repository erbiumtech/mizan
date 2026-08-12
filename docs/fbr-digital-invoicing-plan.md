# FBR digital invoicing: plan

> **Phases 0, 2 and 3 are built** (19 tests, `FbrDigitalInvoicingTest`).
> **Nothing transmits anything** — phase 1 is deliberately not built, see §7.
> Module mechanics are in `docs/new-module-checklist.md`; the CRM side that
> reaches Invoicing is `docs/crms-plan.md` §11 phase 5, which this document
> unblocks.
>
> **Regulatory facts here are researched, not authoritative.** Every figure and
> date in §1 came from public sources in August 2026 and is cited. The
> controlling notification has been replaced at least twice in a year. **Nothing
> in this document is a substitute for confirming the current position with a tax
> advisor**, and §9 lists exactly what to ask.

Three findings shape everything below.

1. **The deadlines have already passed.** SRO 1413(I)/2025 set 1 September 2025
   for public companies, importers and turnover above PKR 1bn, and 1 October 2025
   for turnover between PKR 100m and 1bn. It is August 2026. If the pilot company
   is sales-tax registered above the threshold, this is not a roadmap item.
2. **This application cannot talk to FBR directly.** Integration is only through
   PRAL or an FBR-**licensed integrator**, and multiple integrators are expressly
   permitted. That makes the integrator a vendor choice the company owns and may
   change — so the design is a driver layer, not a client.
3. **`InvoiceService::void()` is an operation this application will no longer be
   allowed to perform.** That is the sharp one, and §2 is about it.

## 1. What the regulation requires

| Requirement | Detail | Confidence |
|---|---|---|
| Who | All sales-tax registered persons, phased by turnover | Multiple sources agree |
| When | 1 Sep 2025 (public cos, importers, >PKR 1bn); 1 Oct 2025 (PKR 100m–1bn, and individuals/AOPs >PKR 100m) | Multiple sources agree |
| How | Through PRAL or an FBR-licensed integrator; registration and testing before going live; **multiple integrators allowed** | Multiple sources agree |
| Reporting | Real-time to FBR's computerised system | Multiple sources agree |
| Invoice carries | IRN (Invoice Reference Number), USIN (Unique Sales Invoice Number), machine-readable QR code for buyer/auditor verification | Multiple sources; **exact field list unverified** |
| Amendment | Cancel/delete/edit **only inside the FBR system**, **only within 72 hours** of issuance. After that, prior approval of the Commissioner Inland Revenue | STGO 01 of 2026, reported consistently |
| Controlling SRO | SRO 709(I)/2025 → SRO 1413(I)/2025 (1 Aug 2025) → **SRO 1852(I)/2025 (24 Sep 2025)** | **Single source for the 1852 supersession — confirm** |

The official STGO 01 of 2026 PDF is a scanned image and could not be read
programmatically, so the amendment rules above are from secondary reporting of
it. That is precisely the sort of detail worth reading in the original before
building against it.

## 2. The collision with what is built

### `void()` stops being available, and nothing currently knows that

`InvoiceService::void()` (line 418) accepts any issued or partially-paid invoice
with no payments recorded, reverses its journal entry, undoes its stock
movements, and sets `STATUS_VOID`. There is no time limit, because until now
there was no reason for one.

Once an invoice has been reported to FBR, that method describes an action this
application is not permitted to take unilaterally: after 72 hours the correction
has to go through the Commissioner. So the operation splits in three, and which
one applies is a function of **time since reporting**, which nothing in the
schema records today:

| Invoice | Correction |
|---|---|
| Never reported (draft, or company not integrated) | `void()` unchanged — it is a local record and always was |
| Reported, within 72 hours | Cancel **at FBR first**, then void locally on success. The local void must not happen if the remote cancel fails, or the books and FBR disagree |
| Reported, past 72 hours | **Refuse the void.** Offer a credit note instead, which is the ordinary accounting answer and the only one available |

The third row is the one to get right, and it is a refusal rather than a feature.
A `void` button that silently produces a locally-voided invoice FBR still
considers live is worse than no button.

**A credit note does not exist in this codebase.** `Invoice` has `KIND_SALE` and
`KIND_PURCHASE` and nothing negative. That is a real gap this creates, and it
belongs to Invoicing rather than here — but it is a prerequisite, not a
follow-up, because without it the "past 72 hours" row has no answer at all.

### `issued` currently means two things it can no longer mean

The status machine is `draft → issued → partially_paid → paid → void`. `issued`
today means "we consider this sent". Under real-time reporting it also has to
mean "FBR accepted it and gave us an IRN", and those two can disagree: an invoice
can be locally issued and remotely rejected.

**Decision: do not add FBR states to `status`.** Reporting is a second axis, not
more values on the first. Cramming `fbr_pending`, `fbr_rejected` into `status`
breaks every existing query that asks `whereIn('status', [ISSUED,
PARTIALLY_PAID])` — the ageing report at `InvoiceService::aging()` does exactly
that, and a rejected-but-real receivable must still age. A separate
`fbr_status` column and a submission log keep both questions answerable.

**The ledger does not wait for FBR.** An invoice posts its journal entry on
issue, as it does now, regardless of reporting outcome. The sale happened; an
FBR rejection is a reporting failure to fix, not a reason to un-record it. This
is the same separation `crms §10` already draws — the ledger is the record, and
everything else is an intention about it.

## 3. Decisions

**It lives in Invoicing, behind a company setting — not a module licence.**
The module system's own rule is that a disabled module is a security boundary and
switching it off must be *safe* (`docs/modules-plan.md`). Switching off statutory
reporting is not safe for a company above the threshold: they would keep issuing
invoices, now non-compliant. A licence is how this application says "you did not
buy this", and compliance is not bought. So: `invoicing.fbr.enabled` in
`TenantSettings`, off by default, with the threshold stated in the help text.

**The integrator is a driver.** Since multiple licensed integrators are permitted
and PRAL is one option among several, the vendor is a per-company configuration
choice. One interface (`submit`, `cancel`, `status`), one implementation per
integrator, one `null` driver that records what it *would* have sent. The null
driver is not a testing convenience — it is how a company runs the whole flow
before its integrator registration and testing are complete, which the
regulation requires before going live.

**Credentials are per-tenant secrets.** Each company has its own registration and
its own integrator account. They belong in the tenant database, encrypted, never
in `config/`. `TenantSettings::set()` is the existing mechanism.

**Submission is queued, not synchronous.** "Real-time" is a reporting obligation,
not a demand that a clerk's save request block on a third-party HTTP call. A job
per invoice, retried with backoff, is both more reliable and the only shape that
survives the integrator being down while somebody is trying to work.

## 4. Schema

```
invoices  + fbr_status        pending | submitted | accepted | rejected | cancelled | not_required
          + fbr_irn           the Invoice Reference Number FBR returns
          + fbr_usin          Unique Sales Invoice Number
          + fbr_reported_at   when FBR accepted it — the clock the 72 hours runs from
          + fbr_qr_payload    what the QR encodes, stored so the PDF is reproducible

fbr_submissions  id, invoice_id, attempt, driver, idempotency_key,
                 request_payload, response_payload, http_status,
                 outcome, error_code, error_message, submitted_at
```

`fbr_reported_at` is the load-bearing column: it is what makes the three-way
correction rule in §2 computable, and without it "can this still be cancelled" is
unanswerable.

`idempotency_key` is not optional. Queued submission plus retries plus an
integrator that timed out *after* recording the invoice is the standard way to
report the same sale twice, and a duplicate at FBR is a correction that needs the
Commissioner.

`fbr_qr_payload` is stored rather than regenerated because a reissued PDF of a
reported invoice must carry the same QR it carried when reported — the same
reasoning the HRMS plan uses for recording the proration divisor on the payslip.

## 5. What the existing FBR export is, and is not, a precedent for

`FbrTaxFile` (Payroll) plus `EmployeeWithholdingTaxExport` is this codebase's
only FBR-facing surface today. It writes the "MONTHLY DETAILS" XLSX for salary
withholding u/s 149 — ten columns, one row per taxed payslip, downloaded by a
human and uploaded to FBR by that human.

**Good precedent for:** where the surface lives (the Reports hub, not the
sidebar — `shouldRegisterNavigation = false`, reached from
`Core\Filament\Pages\Reports`), how it is gated (`BelongsToModule` +
`ReportView`), how FBR's own vocabulary is pinned as class constants rather than
scattered strings (`SALARY_SECTION = '149/4'`), and its honesty about gaps — the
`TaxPayer_NTN` column is written empty with the comment *"not tracked per
employee"* rather than filled with a guess.

**Not a precedent for anything else,** and the difference is the whole risk of
this project. That export is a **pull**: a human chooses a month, gets a file,
and nothing has happened until they act. This is a **push**: the application
transmits, on its own, to a third party, with legal consequences, and a failure
is silent unless something watches for it. A file that does not get downloaded is
a non-event. A submission that does not get accepted is a compliance breach that
looks exactly like a working system.

That is why §4 has a submission log and §7 puts the reconciliation report before
the go-live phase, rather than after it.

The one direct reuse: **NTN is already on `contacts`** (`ntn`, nullable,
`create_contacts_and_invoices_tables.php:19`). The buyer's NTN is a field FBR
wants, the column exists, and it is nullable — so validation before submission,
not a new column.

## 6. What breaks silently

| Thing | What happens |
|---|---|
| A void on a reported invoice past 72 hours | Local books and FBR disagree permanently, and nothing reports the divergence. §2's refusal is the fix |
| A retry after a timeout that FBR actually recorded | The sale is reported twice; correcting it needs the Commissioner. `idempotency_key` is the fix |
| Reporting switched off for a company above the threshold | Invoices keep being issued, now non-compliant, and the application looks perfectly healthy |
| A rejected submission nobody looks at | Identical to success from every existing screen. Needs its own surface and its own notification, not a log line |
| FBR states added to `invoices.status` | The ageing report and every `whereIn('status', …)` silently change meaning |
| A reissued PDF regenerating its QR | The reprint disagrees with what was reported. `fbr_qr_payload` is the fix |

## 7. Phasing

Compliance surfaces land **before** transmission, because a submission nobody can
see failing is worse than no submission.

| Phase | Work | Risk | State |
|---|---|---|---|
| **0** | `fbr_status` / IRN / USIN / `fbr_reported_at` / `fbr_qr_payload` columns, `fbr_submissions` table, `not_required` as the default for every existing invoice | none | **built** |
| **1** | The driver interface + a null driver | low | **deliberately not built — see below** |
| **2** | **The correction rules** — `void()` gains the three-way branch and refuses what FBR would not allow | medium — changes an existing operation | **built**, minus credit notes |
| **3** | Reconciliation report: refused, stuck, accepted-without-IRN, and issued-but-never-reported | low | **built** |
| **4** | A real integrator driver, per-tenant credentials, FBR registration and testing | **high — external, legal** | blocked on §9 |
| **5** | Queued submission on issue, with backoff; QR on the PDF | medium | — |
| **6** | `fbr.enabled` switched on for the pilot, one invoice at a time before the batch | high | — |

**Why phase 1 was skipped rather than done first.** A driver interface is a
guess at the shape of an API nobody here has seen. This codebase already has the
rule and states it in `hrms §4.2` about biometric attendance devices: *"naming a
device vendor in a plan whose author has not seen the device is how you get a
driver nobody can test."* An integrator's API is the same problem with legal
consequences attached. The interface costs nothing to add once §9.4 is answered,
and designing it now would mostly generate work to undo.

What that leaves is the part that is knowable from inside this repository — the
schema, the correction rules and the report — and none of it transmits anything.
`fbr_submissions` therefore exists ahead of its writer, on purpose: the void
rules and the reconciliation report are built against that shape and are worth
having before the failures they describe can happen.

**What is built, concretely:**

- `config/fbr.php` — `enabled` (off), `correction_window_hours` (72),
  `stale_submission_hours` (24), all overridable per tenant through
  `TenantSettings`, because a notification changing 72 must be a settings change
  rather than a deploy.
- `Invoice::isFbrLive()`, `fbrCorrectionWindowClosesAt()`,
  `fbrCorrectionWindowOpen()`, and the six `FBR_*` state constants.
- `InvoiceService::assertFbrAllowsVoid()` — the three-way branch, with the
  existing paid-invoice refusal still ahead of it (asserted, so the ordering
  cannot drift).
- `FbrReconciliation` + the **FBR Invoice Reporting** page, in the Reports hub
  under a new *Statutory reporting* section.

**Still missing, and it is a prerequisite for phase 6, not a follow-up:** credit
notes. §2 explains why — without them the past-window case has no remedy to
point at, only an error message naming one that does not exist.

## 8. Risks

- **Legal exposure is the risk, not technical debt.** Every other plan in this
  directory can slip. This one has dates that have passed.
- **The integrator is a dependency this project does not control** — its API, its
  uptime, its own compliance. The driver layer contains that; nothing removes it.
- **Testing against production FBR is not available.** Phase 4 needs the
  integrator's sandbox, and if there is not one, phase 6's "one invoice at a
  time" is the only test there will be.
- **`void()` has existing callers and existing user expectations.** Taking an
  operation away is harder than adding one, and the person who meets the refusal
  will be mid-correction with a customer waiting.
- **This document will go stale.** Three notifications in under a year. Anything
  built here should treat the field list and the rules as configuration and
  reference data, not as constants in PHP — the same argument §6 of the HRMS plan
  makes for statutory rates.

## 9. What to confirm before phase 4

Questions for the tax advisor, not for this repository:

1. Is the pilot company sales-tax registered, and what is its turnover band?
   That decides whether this is urgent or not applicable.
2. **Which SRO is currently controlling** — 1413(I)/2025, 1852(I)/2025, or
   something later? Sources disagree and the answer changes the deadlines.
3. Given the dates have passed, what is the exposure and the remediation path for
   a company integrating now?
4. PRAL directly, or a licensed integrator? If an integrator: which, what does
   its API look like, and does it offer a sandbox?
5. The exact required field list per invoice, and which are conditional.
6. Does the 72-hour window run from local issuance or from FBR acceptance?
   §4 assumes acceptance (`fbr_reported_at`); if it is issuance, the column and
   the branch in §2 both change.
7. What is the accepted mechanism for correcting a reported invoice after 72
   hours — is a credit note sufficient, or is Commissioner approval required
   even for that?

Sources consulted, all August 2026:
[VATupdate on STGO 01 of 2026](https://www.vatupdate.com/2026/04/11/pakistan-clarifies-integration-and-amendment-rules-for-mandatory-e-invoicing/),
[Business Recorder on licensed integrators](https://www.brecorder.com/news/40414134),
[FBR STGO 01 of 2026 (scanned PDF)](https://download1.fbr.gov.pk/Docs/2026331133557466STGO01of2026.pdf),
[EDICOM on the mandatory schedule](https://edicomgroup.com/blog/pakistan-b2b-electronic-invoicing),
[KPMG on compliance deadlines](https://kpmg.com/us/en/taxnewsflash/news/2025/08/pakistan-compliance-deadlines-e-invoicing.html),
[Regfollower on the updated schedule](https://regfollower.com/pakistan-fbr-updates-e-invoicing-implementation-schedule/).
