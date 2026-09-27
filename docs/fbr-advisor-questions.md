# Questions for our tax advisor: FBR digital invoicing

We run our own ERP (invoicing, accounting, HR) and have built support for
FBR's digital invoicing regime — invoice reporting states, the 72-hour
amendment rule, credit notes under rules 20–22 — up to but not including
actual transmission: nothing is sent to FBR yet, and the connection to
PRAL or a licensed integrator is the one piece deliberately left unbuilt
until the questions below are answered. Our research (public sources,
August 2026) says the phased deadlines under SRO 1413(I)/2025 have already
passed for registered persons above the turnover thresholds, which is why
we would like clear answers before switching anything on.

1. **Is the company sales-tax registered, and which turnover band does it
   fall in?**
   This decides whether digital invoicing applies to us at all, and how
   urgently. It unblocks every other decision — if we are below the
   threshold, the integration stays switched off.

2. **Which SRO currently controls the e-invoicing schedule — 1413(I)/2025,
   1852(I)/2025, or something later?**
   Our sources disagree, and the answer changes the deadlines that apply
   to us. It unblocks knowing which compliance dates we are actually held to.

3. **Given the deadlines have passed, what is our exposure and the accepted
   remediation path for a company integrating now?**
   We need to know whether to expect penalties and whether there is a
   voluntary-compliance route. It unblocks the timeline and budget for the
   integration project.

4. **Should we connect through PRAL directly or through a licensed
   integrator? If an integrator: which one, what does its API look like,
   and does it offer a sandbox we can test against?**
   The integrator is a vendor choice we own, and our software is designed
   to swap between them. Sandbox access unblocks building and testing the
   actual transmission without touching production data.

5. **What is the exact required field list per invoice (IRN, USIN, QR code,
   and the rest), and which fields are conditional?**
   We have this only from secondary reporting. The definitive list unblocks
   finalising our invoice format and validation rules.

6. **Does the 72-hour amendment window run from local issuance of the
   invoice or from FBR's acceptance of it?**
   Our software currently assumes acceptance; if it is issuance, a stored
   timestamp and the void/amend logic both change. The answer unblocks
   locking down when the system must refuse an amendment.

7. **Must credit and debit notes themselves be transmitted electronically
   through the integrated system to be effective (as EY's alert on SRO
   69(I)/2025 suggests)?**
   If yes, an issued-but-untransmitted credit note corrects our books while
   leaving the sales-tax return wrong. The answer unblocks how we treat
   credit notes issued before the integration goes live.

8. **For the 180-day credit-note window under rules 20–22 Sales Tax Rules
   2006: do the 180 days run from the invoice date or from the tax period,
   and can you confirm the 180 + 180 structure (one Commissioner extension
   only)?**
   We read the figures from secondary sources because FBR's PDF is a
   scanned image, and we currently assume the stricter reading (invoice
   date). Confirmation unblocks setting these as trusted values our system
   enforces when refusing or allowing a late adjustment.
