## What this shows

Every account with something posted to it, and every entry against that account
in date order: what it opened at, each line's debit and credit, a running
balance, and what it closed at.

This is the report an audit starts from. The Trial Balance says the books add
up; the General Ledger shows the entries behind each figure it lists, which is
what somebody checking your accounts actually reads.

An account you have posted nothing to, and which opened at nought, is left out.
A chart of accounts carries every account a company might ever use, and a
ledger of empty headings buries the ones you opened the report to read.

## Using it

Set **From** and **To**. They default to the current financial year to date —
which begins on the first day of the fiscal year, not on 1 January.

The **opening balance** beside each account heading is everything posted to
that account *before* the From date, carried forward as a single figure. It is
not listed as a line, because it already belongs to the period before this one.

Total debits and total credits at the top must agree. If they do not, an entry
has been posted with sides that do not match and the books need attention
before anything else on this screen is worth reading.

## Where the figures come from

Only **posted** journal entries. A draft or an approved-but-unposted entry has
changed no balance yet and appears nowhere here — which is the same rule the
Trial Balance and the Balance Sheet follow, so all three agree.

Each account's running balance moves in that account's own direction: a debit
increases an asset and decreases a liability. So a balance here is what the
account *holds*, never a raw debit total.

## Roles and permissions

Requires `ReportView`, gated behind the Accounting module being enabled for the
company. Read-only — nothing on this screen changes a figure.
