## The status is worked out, never stored

Every row's **Status** is derived from its dates, each time the screen is drawn.
There is no status column in the database, and that is the point.

A stored status is the most dangerous silent failure on the payable side: a row
saying `verified` with an expiry three months in the past pays a subcontractor
with no cover, and the screen says everything is fine. Nothing here can drift out
of step with the dates, because there is nothing to drift.

**Days to expiry is signed.** "Expired 40 days ago" and "expires in 40 days" are
different problems, and a column that clamped at zero would lose the difference.

| Status | What it means |
|---|---|
| Valid | In date, and somebody has read it |
| Expiring | In date, inside 60 days of lapsing |
| Expired | Past its expiry, beyond any grace period |
| Unverified | It arrived; nobody has read it yet |
| Not yet effective | Cover starts later than the date being asked about |
| Insufficient cover | A live policy, for less than the contract requires |
| Waived | Accepted as never coming, with a reason on the record |
| Missing | No document of that kind at all |

**Missing is a status, not an absence.** A register that only listed the documents
it had would be a register of good news.

## Received is not verified

Two different facts, two columns. A certificate sitting in an inbox is not a
certificate anybody has read, and only the second one satisfies a requirement —
which is why **Verify** exists as its own action and why the *Received, not read*
filter is worth working from.

## What a requirement is

A requirement says which kinds a contract needs, and per kind:

- **what it blocks** — nothing, certification, payment, or both;
- **grace days**, the days past expiry still tolerated;
- a **minimum sum insured**, where the contract specifies one.

A requirement with **no contract** is the company template: required of every
subcontractor unless a contract says otherwise. The contract's own requirement
wins where both exist, so a subcontract that needs no professional indemnity says
so once rather than the template having to know about every exception.

Grace days are worth setting deliberately. A renewal in the post is ordinary, and
a register that stopped a certificate on the day a policy lapsed would be
overridden every month until somebody set the grace period they should have set at
the start.

A minimum sum insured is checked, not trusted. A subcontractor asked for ten
million of public liability who produces a one-million policy has produced a
document, and a register that only asked whether a document existed would pass it.

## Company, contract, and period scope

A **company**-scoped document covers everything the subcontractor does — a public
liability policy. A **contract**-scoped one covers one contract. A **period**-scoped
one covers one payment: a lien waiver is per payment period where an insurance
certificate is per policy period, and a waiver that covered everything would clear
every payment for the life of the contract.

Period-scoped documents either name the certificate they cover or carry a period
that the valuation date has to fall inside.

## It blocks certification, not payment

A missing or lapsed document that `blocks` certification means the certificate
**will not issue**.

Blocking further downstream, at payment, would leave an approved payable in the
ledger that finance cannot pay — a worse state than a refusal, because the
liability already exists and the stuck payment has nobody's name on it. "You may
not yet certify this" is a sentence somebody can act on.

The rule lives in the certification service, not in a form. A rule enforced only
in a form is a rule that a queue job, a console command or any future API bypasses
in complete silence.

### Judged on the valuation date

Compliance is tested against the certificate's **period end**, not against today. A
June certificate issued in August is judged on the cover that was in force in
June — asking about today would refuse a payment for work that was properly
covered when it was done.

## Overriding <!-- requires: ConstructionComplianceOverride -->

A block can be overridden for **one certificate**, and the reason is mandatory.

A system with no override is a system people work around with a spreadsheet, and
then the register is decorative. So the override exists — but it is recorded
against that certificate with who and when, and it clears nothing else. Certifying
despite lapsed cover once is a judgement about one month; recording it on the
document instead would silently clear every later certificate too.

Overriding when nothing is blocking is refused. It would put a decision on the
file about a risk nobody took.

### Override or waive?

**Override** is about one payment. **Waive** is a standing decision that this
subcontractor will not produce this document at all, and the company accepts that.
Both need a reason, and both are read by whoever later asks why payments went out
without the paperwork.

## The daily warning

`construction:check-compliance` warns before cover lapses, at 60, 30, 14, 7 and
1 days, and mails whoever maintains the register rather than whoever certifies —
the person who can chase an insurer is the person who files the certificates.

**Once per threshold, never once per day.** A job that mails the same warning for
thirty days trains somebody to filter it, and then the one that mattered is
filtered too.

The warning exists so the refusal never has to happen. A first warning delivered
as a refused certificate on payment-run day is a warning delivered too late: the
work is done, the claim is in, and the only options left are chasing an insurer
overnight or overriding.
