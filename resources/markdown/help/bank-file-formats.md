## What this is

The shape of the file your bank accepts for bulk payments. The shipped one is
Standard Chartered's iPayments layout; a designed one is three lines — a
header, a row repeated per payment, a trailer — written over placeholders such
as `{beneficiary_name}`, `{beneficiary_account}` and `{amount}`. One format per
company bank account, or a default every account without its own uses.

## Designing one <!-- requires: BankFileFormatUpdate -->

Click **New**, name it, pick the account, and write the lines. The
placeholder list on the form is everything a line can carry; `{amount:0}`
drops the decimals, `{value_date:Ymd}` is any PHP date format, and
`{beneficiary_name:35}` cuts a field to a width. An unknown placeholder renders
empty rather than leaking into the bank's file.

## Preview before download

A designed format must be **previewed** against the real pending payments
before the bank file can be downloaded, and again after every change. The
preview shows the first rows exactly as the file would carry them.
