This chapter follows money out of the company from end to end: the standing data
a payment needs before it can exist, raising one, approving it, sending it to the
bank as part of a batch, and undoing each of those steps while you still can.

A payment in this system is a record of an intention that becomes a record of
fact. It never moves money by itself — the bank does that, from a file you
download here — so the two things to keep straight are *when the books are
touched* (at approval) and *when the file goes out* (at release). They are
separate steps and they can happen in the same click.

## What has to exist before you can pay anyone

Four pieces of standing data, in this order. Each one is a screen under
**Accounting**.

1. **Banks** — the directory of banks you can transfer *out* to. Each holds a
   bank code, name and short code. Your own bank is deliberately **not** in this
   list: the list is of destinations, and paying within your own bank is handled
   differently (see "Which account number goes in the file" below).
2. **Company Bank Accounts** — the accounts money leaves *from*. Each has a
   title, a bank, an account number and an IBAN, and can be marked the default
   for a given transaction type. Only one account can be the default per
   transaction type; marking a second one moves the flag.
3. **Transaction Types** — what a payment is *for*: rent, food, salary, and so
   on. Each type carries a **default account**, and this is the one piece of
   setup that will stop you later if you skip it. A payment whose transaction
   type has no account cannot be approved at all — the app refuses with a message
   naming the type and telling you to set an account on it. It refuses rather
   than quietly booking nothing, which is what used to happen: the payment went
   out, was marked approved, and never reached the ledger.
4. **Beneficiaries** — who you are paying. Name, bank, account number and/or
   IBAN, an ID type and number, address, email and phone. A beneficiary can also
   carry a default transaction type and a default payment type, and can be
   flagged as a **contractor** (which puts them on the Contractor Payments
   report) or as the **petty cash custodian** (see the Petty cash chapter).

Employees do not need a beneficiary record — you can pay an employee directly,
and their bank details come off their employee record.

## Which account number goes in the file

The bank files are Standard Chartered iPayments format, and the rule is not
obvious:

- A beneficiary who banks **elsewhere** is an inter-bank transfer, keyed on their
  **IBAN**.
- A beneficiary who banks with **your own bank** is an intra-bank transfer, keyed
  on their **plain account number**. The IBAN is the identifier for going out and
  the bank will not accept it here.

The app works out which case applies from the bank's short code, the bank's name,
and the four-letter bank identifier inside a Pakistani IBAN (characters 5–8, e.g.
`PK36`**`SCBL`**`0000…`) — three angles, because your own bank is not in the bank
directory to be matched against.

Two things can be wrong with what is on file, and only one of them stops you:

- **They bank with us but only an IBAN is on file.** This *blocks the release*.
  The row appears in the list with "Wrong kind of account number on file" against
  it and cannot be selected until you add their account number.
- **Nothing on file at all.** This is flagged in the list but does **not** block
  the release, so a row with no beneficiary account can leave in a file the bank
  will reject. Check the flagged rows before you download.

## Raising a payment

There are four ways a payment comes into being. Only the first is typed by hand.

1. **By hand.** Go to **Accounting → Payments → New**. Fill in:
   - **Payable** — an Employee (for a salary or reimbursement) or a Beneficiary
     (rent, food, a supplier).
   - **Transaction Type** — what it is for.
   - **Debit Account** — the company account it comes out of. Leave it empty to
     fall back to the configured default debit account.
   - **Amount** — must be at least 0.01.
   - **Details** — this is the text the bank sees on the transfer, so write it for
     them: "Office Rent July 2026". Capped at 140 characters.
   - **Reference** and **Value Date** — optional.
   - **Payment Type** — leave empty and it is resolved for you: RTGS at or above
     1,000,000 regardless of what the payment is for, otherwise the salary type
     for an employee salary, otherwise BT when payee and payer bank at the same
     bank, otherwise the beneficiary's own default, otherwise IBFT.

   The payment is created as a **draft**.

2. **Salaries, automatically.** One draft payment per payslip for the month,
   raised the moment you open either bank-file page. This is idempotent — a
   payslip can only ever have one payment — and it deliberately leaves released
   payments completely alone, figures included, so a row never disagrees with the
   file the bank already received. See the payroll chapter for how payslips get
   to that point.

3. **Standing monthly payments.** A beneficiary can have **subscriptions** — a
   description, an amount, a due day, and a start and optional end month. Open
   the beneficiary and use **Raise this month** on their Subscriptions tab, or run
   `php artisan accounting:raise-subscriptions`. Each raises an ordinary draft
   payment, once per subscription per month. The due day is clamped to the length
   of the month (a subscription due on the 31st is due on the 28th in February)
   and is never dated earlier than the day you run it. Monthly agreements are not
   pro-rated: a subscription starting on the 15th still bills that month in full.

4. **Petty cash replenishment.** Raised from the Petty Cash Book as a draft
   payment to the custodian. Covered in the Petty cash chapter.

## Approving it — and this is when the books move

Approval needs the **`PaymentUpdate`** permission. Use **Approve Payment** on the
row in **Accounting → Payments**, or select several and approve them together.
Only a **draft** can be approved.

Approving does two things at once:

1. It posts a journal entry immediately — created, approved and posted in one
   step, with no draft stage and no separate approval queue. This is deliberate
   and matches how petty cash and invoices behave. The entry is:
   - **Debit** what the money was for, **Credit** 1100 Cash/Bank.
   - For a payment that settles a payslip — or any payment under the salary
     transaction type — the debit goes to **Salaries Payable**, not to a salary
     expense account. The payslip already booked the wage as an expense and
     raised that liability; debiting the expense again would book the same wage
     twice and leave the liability standing forever.
   - For everything else, the debit goes to the transaction type's own account.
2. It sets the status to **approved**.

A payment for zero gets no entry at all. That is not an error: a month of unpaid
leave can produce a payslip of nothing, payroll still raises the payment, and
there is nothing to record.

Because the entry is posted here, an approved payment is in the Trial Balance and
the Profit & Loss straight away. The chapter on journal entries explains why only
posted entries appear on those reports.

## Releasing a batch and getting the bank file

Open **Bank Payment File** (from the Reports hub — it needs the **`ReportView`**
permission). Choose a fiscal year, a month, optionally a transaction type, and a
value date. The page lists every **unreleased** payment — drafts and approved
ones together.

Two things about that list are worth knowing:

- The month filters **salaries only**. A salary belongs to the month of the
  payslip it pays. Rent or a supplier invoice does not belong to a month at all —
  it is an outstanding payable, and it stays listed until it is released. The
  consequence is that a payable entered months ahead is listed early; its value
  date is on screen and nothing leaves without you pressing Download.
- A salary is only releasable once the **employee has accepted the payslip**.
  That acknowledgement is the entire point of the review step, and paying a
  figure nobody has agreed to is what it exists to prevent. Payments with no
  payslip behind them have nobody to acknowledge them and are releasable as soon
  as they exist.

Press **Download CSV**. In one action the app:

1. Works out the next unused batch reference for the prefix — type, year and month
   — so `SAL-2026-07-B1` is followed by `SAL-2026-07-B2`. Numbering is read off
   what is stored, not a counter, so it stays correct after a void or a restore.
2. Builds the file from the releasable rows.
3. **Approves any drafts among them first**, so nothing can reach the bank without
   being in the books. A payment released without being approved once booked
   nothing at all — no entry, no expense, no trace in the Profit & Loss — while
   the file went out regardless.
4. Marks every one of them **exported**, stamps the batch reference and the
   release time, and streams you the CSV.

Marking them exported is what keeps them out of the next batch. A month's
salaries can legitimately leave in several files as employees accept their
payslips, and nothing may go twice.

Rows held back are listed with a reason against each: already released in an
earlier batch, no longer releasable, the employee rejected the payslip (with
their reason, where they gave one), held back until accepted, or the wrong kind
of account number on file.

## The status after that

The statuses a payment moves through are **draft → approved → exported**.

There is a fourth status, **paid**, which the app honours as a stop — a paid
payment cannot be voided or reverted — but which **nothing in the application
currently sets**. In practice a payment's last state is `exported`, and `paid` is
reserved for a bank-confirmation step that does not exist yet. Do not wait for a
payment to become paid; it will not.

## Undoing a mistake, stage by stage

| Stage | What you can still do |
|---|---|
| Draft | Edit any field. Delete it (needs **`PaymentDelete`**, and only a draft can ever be deleted). |
| Approved | Edit most fields, but not the payment type. Cannot be deleted. The journal entry is already posted. |
| Exported | Void the whole batch, or revert the single row. |
| Paid | Nothing here. Reverse the journal entry instead. |

**To undo a whole file** — the bank rejected it, or the wrong month went out —
use **Void a batch** on either bank-file page (needs **`PaymentUpdate`**). Pick
the batch; every payment in it returns to the pool and appears in the next batch.
Nothing is deleted. Each row goes back to exactly what it was before the release:
approved if it had been approved, draft if it had not. It refuses outright if any
payment in the batch is marked paid.

**To undo one row** — one payee bounced, one set of details was stale — use
**Revert export** on that row in the Payments list instead. Re-issuing an entire
batch to fix one payment is worse than fixing the one.

One thing neither of these does: **voiding or reverting does not un-post the
journal entry.** The payment goes back to approved with its entry intact. That is
usually what you want — the expense was real and the money is still owed — but if
the payment itself was wrong, reverse the entry as described in the chapter on
journal entries, and remember that a posted entry can never be edited or deleted.

## Moving money between your own accounts

Paying yourself is not a payment. Use the bank transfer, which takes money out of
one of the company's own cash or bank accounts and into another as a single
operation: one direction, one description, and marked as a transfer so it can be
told apart from a payment a year later.

It only accepts the company's own register accounts — active 11xx asset accounts
that take manual entries. A "transfer" to an expense account is refused with
exactly that explanation: money leaving the company is a payment, not a transfer.
It also refuses a transfer to the same account, and any amount that is not
positive. The entry is posted immediately: debit the destination, credit the
source.

This exists because the same move was previously possible as a hand-written
two-line entry on the register, which meant the same 50,000 moved from the bank
to petty cash could be entered two different ways by two people and read as two
unrelated events.

## Seeing what actually went out

- **Contractor Payments** (Reports hub, **`ReportView`**) — what each contractor
  received over a fiscal year, the local equivalent of a 1099. It counts only
  money that actually left: exported or paid. A draft is an intention and an
  approved payment is a decision, and neither belongs on a statement of what
  somebody received. Contractors with nothing paid are left off entirely.
- **Export Payment Details** — an action on the Payments list, for getting the
  underlying rows out.
- Every void and every revert is written to the activity log with the batch
  reference, the payments involved and the total, so the history of a re-issued
  file is recoverable after the fact.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `PaymentView` | See the Payments list |
| `PaymentCreate` | Raise a payment |
| `PaymentUpdate` | Edit, approve, revert an export, void a batch |
| `PaymentDelete` | Delete a payment — drafts only |
| `ReportView` | Open the Bank Payment File and Contractor Payments pages |

Note that approving a payment and voiding a released batch are both
`PaymentUpdate`: there is no separate approval permission for payments, unlike
journal entries. Anyone who can edit a payment can approve it and can pull a
batch back.
