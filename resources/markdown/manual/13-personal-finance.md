Every other chapter in this manual is about the company's money. This one is
about yours.

Personal Finance is a set of books that belong to you rather than to the
business: what you earn, what you spend it on, what you own and owe, and roughly
what you will owe in tax. It uses the same double-entry bookkeeping as the
company ledger, so the numbers hold together — but you never have to think in
debits and credits to use it.

## Before you start

Two things worth knowing up front, because they change what this is.

**Your records are private, with one honest exception.** Nobody else using the
app sees your accounts or your transactions. An Administrator can be granted a
view across everyone's records, but cannot change or delete anything of yours —
that is enforced in the data layer, not by a permission somebody could grant
themselves. The exception: an Administrator can sign in *as* you (impersonation,
which exists across the whole app), and while signed in as you they see what you
see. Every impersonated action is recorded in the audit log against whoever was
really behind it.

**Your books live in this company's database.** If you use this app for two
companies, you have two separate personal ledgers, one in each, and neither
knows about the other. There is no single view across both.

The module is off until a company licenses it. An administrator turns it on
under Settings → Modules, described in *Setting up a company*.

## 1. Set up your accounts

Go to **Personal → My Accounts**. On a fresh account there is a **Set up starter
accounts** button: it creates cash and bank accounts, common expense categories —
Food, Rent, Education, Utilities, Transport, Medical — and income categories
already tagged with how they are taxed.

Take it, then make it yours. Rename anything, close what you do not need, and add
what is missing. The starter set is a way to avoid a blank screen, not a
structure you have to keep.

Each account has:

- a **code** — a short reference like `1000`, unique only to you, so somebody
  else can have a `1000` too;
- a **type** — asset, liability, income, expense or equity, which decides where
  it shows up in your reports;
- an **opening balance** — what was already there the day you started tracking;
- and, on income accounts only, **Taxed as**, which is what drives your tax
  estimate.

If you already have money in an account, set its opening balance rather than
inventing a transaction for it.

## 2. Record what happens

Go to **Personal → My Transactions**. There are three buttons, and between them
they cover everything:

1. **Record income** — money arriving. Pick what kind of income, and which
   account it landed in.
2. **Record expense** — money going out. Pick what it was for, and which account
   it came from.
3. **Transfer** — money moving between two of your own accounts. Your net worth
   does not change; the money is just somewhere else.

Behind each one the app writes two balanced lines, which is what makes the
reports add up. You never see that unless you want to.

There is no edit button. An entry is a matched pair, and an edit form that
rewrites both halves without breaking the balance is more machinery than this
needs — so to fix a mistake, delete the entry and record it again.

## 3. See where you stand

**Personal → My Balance Sheet** answers "what am I worth right now": everything
you own, everything you owe, and the difference. It has no date picker on
purpose — it is always as of now.

**Personal → Income & Expenditure** answers "what happened over a year": what
came in, what went out, broken down by category with each one's share of the
total. Pick the tax year at the top. This is the screen that tells you what
education, or rent, or anything else actually cost you.

## 4. Estimate your tax

**Personal → Tax Estimate** works out roughly what you owe for a tax year, based
on what you have recorded.

It groups your income by the **Taxed as** setting on each income account —
salaried, business, rental or capital gains — applies the brackets for that year,
and shows its working: which bracket applied, the rate on the excess, and the
resulting tax for each kind of income.

Income sitting on an account with no **Taxed as** set is listed separately as
uncounted. It is not guessed at, because a confident wrong number is worse than
an obvious gap. Set the field on those accounts and it will be included.

**This is an estimate, not tax advice, and the screen says so.** In particular it
does not know about:

- tax already deducted from you at source, including by your employer through
  payroll;
- tax credits and deductible allowances;
- the surcharge on high salaried income;
- the full rental and capital gains schedules, which depend on the asset and how
  long it was held — those use indicative flat rates here.

It also assesses each kind of income on its own schedule and adds the results,
which is a simplification of how a real return aggregates them.

If a year has no brackets set up, the screen says so plainly instead of showing
zero. That distinction matters: "nothing is configured" and "you owe nothing"
look identical if a tool reports both as 0, and only one of them is good news.

## Tax years

The tax year here runs July to June, the same as Pakistan's, and it is the same
fiscal year the rest of the app uses.

One naming difference to be aware of: FBR names a tax year for the year it
*ends* in, so July 2025 to June 2026 is FBR's **Tax Year 2026**, while this app
labels that same period **2025-2026**.

## Roles and permissions

| Permission | What it allows |
|---|---|
| `PersonalFinanceView` | See your own accounts, transactions and reports |
| `PersonalFinanceCreate` | Add accounts and record transactions |
| `PersonalFinanceUpdate` | Edit your own accounts |
| `PersonalFinanceDelete` | Delete your own accounts and transactions |
| `PersonalFinanceViewAny` | View across everyone — Administrator only |

Every seeded role — Employee, Accountant, Manager, CEO and Administrator — gets
the first four. This is deliberately not a finance-department feature: keeping
track of your own money is not a privilege.

Holding a permission never lets you reach somebody else's records. Which rows you
can see is decided by who owns them, not by what you have been granted.
