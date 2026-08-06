## What this screen is

Your own chart of accounts — the list of places your money sits, the things you
owe, and the categories your income and spending get filed under. Nothing here
belongs to the company. This is your own set of books, kept alongside the
company's but entirely separate from them.

Every account has a **type**, and the type decides where it shows up:

- **Asset** — something you own: cash in hand, a bank account, property.
- **Liability** — something you owe: a loan, a credit card balance.
- **Income** — money coming in: salary, rent you collect, business takings.
- **Expense** — money going out: food, rent you pay, education, fuel.
- **Equity** — your own capital. Most people never need to add one.

Assets and liabilities make up your **Balance Sheet**. Income and expenses make
up your **Income & Expenditure** summary for the year.

## Who can see this

Your records are yours. Another person using this app — a colleague, your
manager — cannot see your accounts or your transactions at all. They do not
appear in their lists, and a direct link to one of your records will not open
for them.

Two honest exceptions worth knowing rather than discovering:

- An **Administrator can view** everyone's personal records, but **cannot edit
  or delete** anything of yours. Viewing across people is a deliberate,
  separately granted permission, not something every administrator uses by
  accident.
- An Administrator can also **sign in as another user** (impersonation, a
  feature that exists across the whole app). While signed in as you, they see
  what you see. Nothing that keys off "who is signed in" can tell the
  difference. Every impersonated action is recorded in the audit log with who
  was really behind it.

None of your personal money ever reaches the company's books. It cannot appear
on the company Trial Balance, Profit & Loss, Balance Sheet or Account Register —
those read a completely different set of records.

One more thing: this is stored in **this company's** database. If you use this
app for two different companies, you have two separate personal ledgers, one in
each, and neither shows the other.

## Getting started quickly <!-- requires: PersonalFinanceCreate -->

If you have no accounts yet, a **Set up starter accounts** button appears. It
creates a sensible starting set:

- Cash and a Bank account
- Loans and a Credit card
- Salary, Business income, Rental income and Other income — the first three
  already tagged with how they are taxed
- Food & groceries, Rent, Education, Utilities, Transport, Medical and Other
  expenses

Rename, close or delete anything that does not match how you actually keep your
money. The button disappears once you have any accounts, so it cannot quietly
add fifteen rows to a chart you have already built. Running it a second time
only adds what is missing — if you deliberately deleted Rent, it stays deleted.

## Adding an account yourself <!-- requires: PersonalFinanceCreate -->

Click **New**. You will fill in:

- **Code** — a short reference of your own, like `1000` for cash. It only has to
  be unique to *you*: two people can both have a `5300 Education` without
  clashing.
- **Name** — what you call it.
- **Type** — one of the five above.
- **Taxed as** — appears only on Income accounts. See below; it matters.
- **Opening balance** — what was already there when you started tracking. Leave
  it at zero if you are starting fresh.
- **Active** — on by default.

## Tagging income with how it is taxed <!-- requires: PersonalFinanceCreate, PersonalFinanceUpdate -->

The **Taxed as** field on an income account is what makes the Tax Estimate work.
Set it once and every amount you ever book against that account is classified
automatically — you never have to think about it again when recording a
transaction.

The choices are **Salaried**, **Business / self-employed**, **Rental / property
income** and **Capital gains**, because Pakistan taxes each of them on a
different schedule.

Leaving it blank is allowed and safe: that income is reported as
**unclassified** on the Tax Estimate and **not taxed**, rather than being
guessed at and quietly folded in as salary. A blank is visible; a wrong guess
would not be.

## Closing an account <!-- requires: PersonalFinanceUpdate -->

Switch **Active** off. The account and all its history stay exactly where they
are and keep counting towards your balances — it simply stops appearing in the
lists when you record something new. This is what you want for a bank account
you have closed or a category you no longer use.

## Deleting an account <!-- requires: PersonalFinanceDelete -->

Possible, but closing is almost always the better answer. Deleting an account
that already has transactions against it will leave those transactions pointing
at nothing. If you have used it, close it instead.

## Balances

The **Balance** column is worked out from your transactions every time the page
loads, not stored anywhere. That means it cannot drift out of step with the
entries behind it — but it also means it always reflects everything you have
recorded, including entries dated in the future.

Assets and expenses grow when money moves *into* them; liabilities, income and
equity grow when money moves *out*. That is why an expense category shows a
positive balance as you spend against it.

## Quick answers

**Can my manager or the company see what I spend?**
Not through this module. Another ordinary user sees nothing of yours at all. An
Administrator can view but not change your records, and can also impersonate
you, which is recorded. See "Who can see this" above.

**I already have a `1000` — why did it let someone else create one too?**
Codes only need to be unique per person. Your chart is yours; theirs is theirs.

**Why is there no Balance column total?**
Adding up assets and expenses together would not mean anything. The Balance
Sheet totals what you own and owe; Income & Expenditure totals what you earned
and spent.

**I set up starter accounts but I want a completely different structure.**
Delete or rename them freely — nothing depends on those particular names or
codes. Only the *type* matters to the reports, and only **Taxed as** matters to
the tax estimate.
