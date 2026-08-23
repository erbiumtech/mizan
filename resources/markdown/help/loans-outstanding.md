## What this shows

Every active loan on one page: what is still owed, the interest still to come, the
instalments falling due in the next twelve months, how far through the schedule
the loan is, and the next payment date.

Until this report existed you could open any one loan and read its amortisation
table, and there was no way to ask what the company owed in total.

## The two totals, and why both are there

At the top the report states **Outstanding** — what the schedules say is left —
beside **Liability accounts** — what the ledger says. The line underneath says
whether they agree.

That difference is the most valuable figure on the report. Schedules and liability
accounts drift apart for real reasons, and each of them is something somebody
needs to know about:

- an instalment was paid outside this application, so the account moved and the
  schedule did not;
- somebody posted a manual journal entry against the liability account;
- a loan was restructured and its schedule was never rebuilt.

If they agree, the report says so in as many words. That is deliberate: two
similar-looking numbers side by side with no comment invite a reader to decide for
themselves whether the difference matters.

## Using it

The date is "as at". The ledger balance is everything **posted** up to and
including it, and the twelve-month window runs forward from it.

**Outstanding** for each loan is the closing balance on the last instalment that
has actually been recorded — what the agreement says is left, not a restatement of
the account. A loan with nothing recorded yet shows its full principal rather than
nought.

**Interest to come** is the interest on the instalments not yet recorded. Interest
already paid is in the profit and loss, not here.

**Due in 12 months** is the total of the unrecorded instalments dated inside the
next twelve months — the figure a balance sheet note wants for the current portion
of long-term debt.

**Instalments** reads `7 of 60`. A loan whose schedule has never been generated
reads *No schedule*, which is a different thing from a loan with nothing paid.

**Next due** is the date of the first unrecorded instalment, or *Settled* when
every one has been recorded.

## Where the figures come from

**Only active loans.** A loan that has been settled or deactivated is not listed,
and its liability account is not counted in the comparison either — so a
deactivated loan with a balance still sitting in the accounts will not show up as
a difference here. That is worth knowing if the two totals agree and you expected
them not to.

**Only posted journal entries** count towards the account balance, which is the
same rule the Trial Balance and the Balance Sheet follow, so all three agree.

**Where two loans share a liability account** its balance is counted once, not
once per loan.

## Roles and permissions

Requires `ReportView`, gated behind the Accounting module being enabled for the
company. Read-only — nothing here records an instalment, rebuilds a schedule or
posts an entry.
