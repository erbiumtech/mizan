# AI Command Bot — Plan

**Status:** **Built, Phases 0–4, 2026-08-25.** The plan is complete as written.

Phase 0's decisions, written back as that phase requires: the provider is **Anthropic**
(`anthropic-ai/sdk`, the only LLM dependency in the project); voice is **out of scope until Phase 3**, for
the reason §5 gives; the confirmation threshold is **100,000**, configurable per install via
`AI_CONFIRM_THRESHOLD`. The whole feature is **off by default** — `AI_COMMANDS_ENABLED` plus a key, or the
command bar does not render at all.

Two things the build found that this document had not anticipated, both now fixed:

- **Neither shipped category list had an income category.** `TransactionTypeSeeder` was eleven expense,
  asset and liability categories and `PersonalTransactionTypeSeeder` ten expenses — both written for the
  payment screens, where money only ever goes out. Half of §2's vocabulary therefore had nothing to
  resolve to. Eight income categories were added across the two charts.
- **A grouped amount silently lost two digits.** `25,000` parsed as `25` — the separator was replaced with
  a space and the number match took the first run of digits. Booked, that is a hundredth of the intended
  figure with nothing to complain about, since 25 is a valid amount. It is the §3.1 failure mode arriving
  through a different door than the one that section watches.

**What is being asked for:** say or type *"rent 25000 out"* — or *"کرایہ ۲۵۰۰۰ جمع"* — and have a
transaction appear in the right module, correctly signed, against the right account.

---

## §1 The finding this plan turns on

The application already has the exact shape a command has to produce. `RegisterEntryService::bookRow()`
takes a cash/bank account, a counter-account, and a `$data` array whose direction field is documented in
those words:

```php
$direction = $data['direction']; // 'in' (debit column) or 'out' (credit column)

// Money out (credit column) → credit bank, debit transfer.
// Money in  (debit column)  → debit bank, credit transfer.
```

**So the bot never does accounting.** It resolves words into four values — direction, amount, category,
date — and hands them to a service that has been booking balanced, posted, audited two-line entries since
long before this feature existed. Everything hard about double-entry (which leg is which, fiscal-year
guards, posting rules, immutability) stays where it already works.

That is the whole architectural claim, and it is worth stating plainly because the alternative is
seductive and wrong: a bot that emits journal lines directly would have to re-derive the sign convention,
the approval treatment, and the register's own rule about which entries it is allowed to own — three
things `RegisterEntryService` already gets right and two of which have no test coverage outside it.

---

## §2 The accounting rules this follows

A debit (Dr.) is an entry on the left side of an account ledger; a credit (Cr.) is an entry on the right.
Every transaction uses both, and **total debits must always equal total credits.** Whether a debit or a
credit increases an account depends on the account's type:

| Account type | Debit | Credit |
|---|---|---|
| **Asset**, **Expense** | increase | decrease |
| **Liability**, **Equity**, **Revenue** | decrease | increase |

`RegisterEntryService::bookRow()` implements exactly this, and `JournalEntryService` refuses an entry
whose two sides do not balance — so the equality above is enforced by the code, not by convention.

### 2.1 Why the requested vocabulary is unconditionally correct here

The requested mapping:

| Money **in** | Money **out** |
|---|---|
| in, add, jama, debit | out, minus, less, credit |

Both halves are right, and the reason is a constraint in the code rather than a judgement call.
`registerAccounts()` is filtered `->where('type', 'asset')` — a register account is **always** an asset
(the 11xx cash and bank family). By the table above, a debit therefore always *increases* it. So:

- Money received → the cash account increases → **debit** the cash account.
- Money paid out → the cash account decreases → **credit** the cash account.

There is no account type in this feature for which that inverts, so `debit = in` and `credit = out` hold
without qualification. That is what `bookRow()` already does.

### 2.2 The counter-leg is automatic — the bot never classifies it

This is the part that makes the four-slot grammar sufficient. Because the cash leg is fixed by direction,
the other leg is fixed by "total debits equal total credits" — it is simply the opposite side, whatever
the counter-account happens to be. The account-type rules then decide what that *means*, and they come out
right in every case without the bot knowing anything about the account:

| Command | Cash leg | Counter leg | Counter type | Effect there |
|---|---|---|---|---|
| Paid rent 25,000 | Cr Cash 25,000 | Dr Rent Expense | Expense | increase ✓ |
| Received salary 200,000 | Dr Bank 200,000 | Cr Salary Income | Revenue | increase ✓ |
| Took a loan 500,000 | Dr Bank 500,000 | Cr Loans | Liability | increase ✓ |
| Repaid loan 50,000 | Cr Bank 50,000 | Dr Loans | Liability | decrease ✓ |
| Owner put in 100,000 | Dr Bank 100,000 | Cr Owner Equity | Equity | increase ✓ |

Five account types, five correct answers, from one `direction` field. **So the model resolves a direction
of money and a category — never a ledger side, and never an account type.** Asking it to output "debit" or
"credit" would be asking it to re-derive a table the service already applies correctly.

### 2.3 What the confirmation is still for

The rule is unambiguous; the *speaker* may not be. Two habits produce a correct system doing the wrong
thing:

- **Bank-statement English.** A statement credits your account when money arrives, because it is written
  from the bank's books rather than yours. Someone reading their statement all their life may say
  "credit" meaning money in — the inverse of what it means in their own ledger.
- **Bahi-khata Urdu.** In traditional Urdu bookkeeping جمع (jama) heads the credit column and نام (naam)
  the debit column, while colloquial *jama karna* means to deposit.

Neither changes the rule. Both are reasons the confirmation in §6 states the effect in plain words —
"**money out** — 25,000 leaves Cash / Bank" — rather than echoing the word the user typed. A user who
meant the opposite catches it there, which is the only place they can.

The unambiguous words need no such care: in/add/out/minus/less, and the Urdu آیا / ملا / وصول (came in,
received) against گیا / دیا / خرچ (went, gave, spent).

---

## §3 What a command has to resolve to

Four slots. Everything else is optional.

| Slot | Resolves to | Source of truth |
|---|---|---|
| **direction** | `'in'` \| `'out'` | §2 |
| **amount** | positive decimal | §3.1 |
| **category** | a `TransactionType`, which carries `account_id` | §3.2 |
| **date** | a date | §3.3 |

`TransactionType` is the pivot and it already exists for exactly this purpose: a named, per-tenant
category (`rent`, `food`, `utilities`, `transport`, `medical`) with `account_id` pointing at the chart.
`PersonalTransactionTypeSeeder` maps ten of them for a household; `TransactionTypeSeeder` maps eleven for
a business. **The bot resolves a spoken category name to a `TransactionType`, and the account comes for
free.** It never picks an account code directly — that would put chart knowledge in a prompt, where it
would drift the first time somebody renamed an account.

### 3.1 Amounts

Pakistani number words and both digit sets:

- `hazaar` / ہزار / `k` → ×1,000; `lakh` / لاکھ → ×100,000; `crore` / کروڑ → ×10,000,000
- `1.5 lakh`, `paune do lakh`, `25k`, `pachees hazaar`
- **Urdu-Indic digits** ۰۱۲۳۴۵۶۷۸۹ must be normalised before parsing — a `۲۵۰۰۰` that reaches `(float)`
  becomes `0.0` silently, which books a zero-amount entry rather than failing. `bookRow()` refuses
  `$amount <= 0`, so this surfaces as an exception rather than a bad row — but the message will be
  useless. Normalise at the edge.

### 3.2 Categories

Resolution is **lookup, not generation**: the model is given the tenant's actual `TransactionType` list
(code + name) in the prompt and must return one of those codes or `null`. It may not invent one. A `null`
means "ask" — never "use Other", because a bot that silently files things under Miscellaneous produces a
ledger that balances and tells nobody anything.

Aliases are data, not prompt text: a small `transaction_type_aliases` table (`transaction_type_id`,
`alias`, `locale`) lets a tenant teach it that *kiraya* / کرایہ is rent and *bijli* / بجلی is utilities,
without a code change and without a per-tenant prompt.

### 3.3 Dates

Default: today. Relative words: `aaj` (today), `parson` (day before yesterday / day after tomorrow),
`pichlay hafta`, `is mahinay`.

**`kal` (کل) means both yesterday and tomorrow.** Urdu marks the difference by verb tense, which survives
in speech and often not in a three-word command. `kal rent 25000 out` is genuinely ambiguous. Resolve
from tense where the sentence has one; otherwise default to **yesterday** (a command about a transaction
is nearly always about one that has happened) and show the resolved date in the confirmation, which is
where the user catches it.

Dates use `Illuminate\Support\Carbon` throughout — not `Carbon\Carbon`. The distinction is load-bearing
in this codebase.

---

## §4 The model call

**Claude, via the official PHP SDK** (`composer require anthropic-ai/sdk`) — the project is Laravel/PHP,
so the SDK is the supported path rather than hand-rolled HTTP.

Model **`claude-opus-5`** at **`effort: 'low'`**. Effort is the cost and latency lever here, not model
choice: this is a short, well-specified extraction with a closed category list, which is the shape `low`
is for. If the running cost turns out not to justify it, moving to Haiku is a decision to take
deliberately with numbers in hand, not a default to assume.

**Structured outputs, not tool use.** The model does one job — turn a sentence into four fields — and
`output_config.format` with a JSON schema constrains it to exactly that. A tool-use loop would let the
model call `bookRow` itself, which is precisely the authority this design withholds from it.

```php
$response = $client->messages()->create(
    model: 'claude-opus-5',
    maxTokens: 1024,
    system: $prompt,                       // stable prefix — see caching below
    outputConfig: [
        'effort' => 'low',
        'format' => ['type' => 'json_schema', 'schema' => $SCHEMA],
    ],
    messages: [['role' => 'user', 'content' => $utterance]],
);
```

The schema returns `direction`, `amount`, `transaction_type_code`, `date`, `description`, plus a
**`confidence`** and an **`ambiguity`** array naming any slot the model guessed at — a category it matched
loosely, an amount it inferred from a fragment, a `kal` it resolved by default rather than by tense.
Those two fields drive §6 and are the reason this is worth doing with a model at all rather than a regex.
`direction` does not appear in that array: §2.1 makes it a rule, not an inference.

> The PHP SDK's exact named-argument spelling must be checked against the SDK repo before implementation.
> Top-level arguments are camelCase (`maxTokens`, `outputConfig`), but nested array keys are wire names
> and vary per feature — they are not bulk-convertible.

**Prompt caching.** The system prompt carries the tenant's category list and the alias table — stable
across every command that tenant sends, and the volatile part (the utterance) sits in `messages`, after
it. That is the correct shape for a cache breakpoint on the last system block. The one thing that must
*not* go in the system prompt is today's date: it changes the prefix daily and invalidates the cache for
every tenant at midnight. Pass it in the user turn.

---

## §5 Voice

**Claude has no speech-to-text endpoint**, so voice is a separate decision, and it is the weakest part of
this plan:

| Option | Urdu | Cost | Notes |
|---|---|---|---|
| Browser `SpeechRecognition` | Poor-to-unusable for `ur-PK`; better for `en-IN` | Free | No server round trip, no audio leaves the device. Chrome-only in practice. |
| Third-party STT API | Usable, varies by vendor | Per-minute | Audio leaves the tenant's control — a real consideration for a bookkeeping app. |

**Recommendation: ship text first, and treat voice as a strictly additive second phase.** Text-mode
proves the parsing, the resolution and the confirmation flow — the parts that carry money. Bolting an
unreliable transcriber onto an unproven parser makes both failures look like one failure and neither is
diagnosable.

When voice does land, the transcript is shown and editable before it is parsed. Speech errors and parse
errors must be separable by the person using it.

---

## §6 The rule that keeps this safe

**`bookRow()` approves and posts immediately.** There is no draft state, no review queue — a booked row is
a posted journal entry the moment the call returns, and `RegisterEntryService::immutableReason()` governs
whether it can ever be edited again.

So: **no command posts without an explicit confirmation.** Not "high confidence skips it" — always. The
confirmation shows the resolved interpretation in full sentences, in the language the command was given
in:

> **Money out — 25,000**
> Rent (5200 Rent) · today, 25 Aug 2026 · from Cash / Bank
> `Confirm`  `Change`  `Cancel`

The first line is the load-bearing one, and it states the **effect** rather than echoing the user's word:
"money out — 25,000 leaves Cash / Bank", never "credit 25,000". §2.3 is why — the rule is unambiguous but
the speaker's habit may not be, and this line is the only place a bank-statement "credit" gets caught.

Anything the model flagged in `ambiguity` is highlighted rather than merely displayed. A command with a
`null` category, or an amount above a per-tenant threshold, cannot be confirmed by pressing Enter — it
requires the field to be picked explicitly. **Direction is never in that set**: by §2.1 it is determined
by the rules, not guessed, so it is displayed prominently and confirmed like everything else rather than
singled out for extra friction.

This is not a temporary safety measure to be relaxed once accuracy is good. The cost asymmetry is
permanent: a wrong entry is silent, lands in a posted ledger, and is found weeks later by somebody
reconciling — while a confirmation step costs one keystroke.

---

## §7 Beyond cash

The four-slot grammar is a cash-register grammar. It covers the request as stated and it should be the
whole of phase 1.

Other modules have their own shapes, and each needs its own resolver rather than a wider prompt — an
expense claim has a claimant and a project, a payslip has a period and an employee, a construction cost
has a job, a WBS node and a cost code (three lookups before an amount means anything). The extension
point is a **`CommandResolver` contract** mirroring the command palette's `PaletteProvider`: each module
registers a resolver, declares the utterances it can claim, and returns a preview object plus a closure
that commits. The bot routes; it does not learn every module's schema.

Module boundaries are enforced the way everything else in this application enforces them:
`modules()->enabled()` gates which resolvers are even offered, and the user's own permissions gate what
they can commit. A resolver for a module this tenant has not licensed must not appear in the prompt at
all — not merely be refused after the fact, which would leak the module list.

---

## §8 Where it lives

The **command palette** (`docs/command-palette-implementation.md`) is the obvious host and mostly the
right one: it is already rendered globally via `PanelsRenderHook::BODY_END`, already a Livewire component
holding query state, already permission- and tenant-aware, and already the thing users press ⌘K for.

The one thing it is not is *conversational*. The palette navigates — every provider returns items with a
URL. A command bot proposes an action and waits for confirmation, which is a second interaction the
palette has no concept of. So: same surface, same keystroke, but a distinct `CommandBar` component rather
than a fifth `PaletteProvider`, sharing the palette's tenant/permission plumbing and its render hook.

### §8.1 How it is opened — corrected after shipping

The bar first shipped reachable **only** by ⌘J, and ⌘J does not reach the page. It is a reserved browser
shortcut on both platforms — Chrome and Firefox open Downloads with it, dispatched from the native menu
bar before the document sees the keystroke — so `.prevent` runs too late to take it back. The feature was
unreachable, and unreachable *silently*: no dialog, no error, nothing in the log. Every test passed the
whole time, because the component was never the broken part.

Two changes, and the second is the one that matters:

1. The hotkey is **⌘/** (ctrl+/ elsewhere), which no browser claims. ⌘J is still honoured for anyone whose
   muscle memory has it and whose browser leaves it free, but it is no longer the way in.
2. A **button in the topbar**, beside the ⌘K search trigger, dispatching `open-command-bar`. This is the
   primary affordance; the hotkey is the shortcut. The palette next door already had one, and the reason
   generalises: a shortcut is not discoverable, and this one has no menu entry to be discovered from.

### §8.2 And then it closed itself — the second half of the same symptom

Opening it revealed a second bug wearing the same face. `showModal()` opens a `<dialog>` by setting an
`open` **attribute**; interpreting is a Livewire round trip; Livewire morphs the response over the live
DOM; the server HTML has no `open` on it. So the morph removed the attribute the browser had set and the
dialog shut on Enter — the proposal rendered correctly, into a box that was no longer on screen.

Fixed with `wire:ignore.self` on the dialog, which maps to Alpine morph's `childrenOnly()`: the element's
own attributes are left alone while everything inside still updates. Plain `wire:ignore` — what the ⌘K
palette uses — would freeze the proposal, and the proposal is the one thing here that must change. The
palette gets away with it because it renders results from an Alpine array rather than from the server.

Neither of these was catchable by the suite as built: one lived in a keystroke the browser intercepts, the
other in a DOM morph PHPUnit does not perform. **Both looked like backend failures from the outside and
neither was** — every server-side test passed throughout. The lesson worth keeping is that "the component
returns the right state" and "a person can reach that state" are different claims, and only the first one
was ever being tested.

The button and the dialog both ask `CommandBar::available()` rather than each deciding for itself, because
the two ways they can disagree are the two failure modes: a button that opens nothing, and a dialog with
no button. That predicate now also reads `ai.enabled`, which nothing had read until this point — with the
`local` driver `isConfigured()` is unconditionally true, so the feature flag had quietly stopped being a
flag.

Tenancy is not negotiable and is easy to get wrong here: the model call happens outside the request's
normal Eloquent flow, so the resolved `TransactionType` and `Account` must be re-fetched **inside** the
current tenant before `bookRow()` is called. An id that arrived from a model response is a string from
outside the system, not a trusted key.

---

## §9 Audit and undo

Every command writes a `command_utterances` row: the raw text (and transcript, if spoken), the model's
full structured response, the confidence, the resolved ids, the user, the tenant, and the resulting
`journal_entry_id` — or the reason it was abandoned. Two things need this. Correction: when somebody asks
why the ledger says what it says, the answer has to include what was said and what the machine made of
it. And improvement: the alias table in §3.2 is only maintainable if there is a record of which
utterances failed to resolve.

Undo is `RegisterEntryService`'s existing `deleteRow()` and `immutableReason()` — the bot gets no special
power to unpick a posted entry, and inherits the rule that the register only owns rows it booked itself.

---

## §10 What this will not do

Named so that nobody is surprised later:

- **No free-form accounting.** It books register rows. Journal entries with three legs, accruals,
  reversals and reclassifications stay in the accounting screens where they can be seen whole.
- **No editing by command.** "Change yesterday's rent to 30000" is a second, harder feature — it needs
  the bot to identify an existing row, which is a search problem with a much worse failure mode than
  creating one.
- **No queries.** "How much did I spend on food last month?" is a reporting question and this application
  has a reports module. Answering it from a chat box would create a second, unversioned reporting path.
- **No bulk.** One command, one transaction.

---

## Phases

**Phase 0 — Decide. DONE.** The provider (Anthropic, confirmed by §4), whether voice is in scope at all, and
the per-tenant confirmation threshold. Ends with those answers written back into this document.

**Phase 1 — Text, cash only, English. DONE.** `CommandBar`, the four-slot schema, `TransactionType` resolution,
the confirmation panel, `command_utterances`, and `bookRow()` behind it. Ends with a feature test that
sends twenty utterances and asserts the resolved slots. Two of those twenty are the sign test: assert
that a money-in command debits the cash account and credits the counter-account, and a money-out command
does the reverse — the §2 table, asserted rather than assumed.

**Phase 2 — Urdu. DONE.** Digit normalisation, the alias table, Urdu number words, `kal` and its tense rule.
Ends with the same test in Urdu, plus a test that `۲۵۰۰۰` never books as zero.

**Phase 3 — Voice. DONE (browser recogniser).** Transcript shown and editable before parsing. Ends with speech errors and parse
errors being separately visible in `command_utterances`.

**Phase 4 — The resolver contract. DONE.** Extract `CommandResolver`, move cash behind it, add one more module
to prove the seam is real. Do not build this seam in phase 1 — a boundary drawn around one
implementation is a boundary drawn around that implementation's accidents.

---

## Risks

**The user meant the other direction and nobody notices.** Note what this risk is *not*: the sign rule
itself is settled (§2) and enforced by a service with tests, so the machine will not get debit and credit
backwards. The residual risk is a person saying "credit" in the bank-statement sense and getting a
correctly-booked entry in the wrong direction. It is the only failure here that produces a ledger which
balances and lies, and the plain-words confirmation line in §6 is the whole of the defence.

**Category drift.** A tenant renames a `TransactionType` and the aliases still point at the old word.
Aliases are per-`transaction_type_id`, not per-name, so a rename survives — but a *merge* does not, and
`transaction_type_aliases` needs the same `ON DELETE` care as everything else pointing at a tenant table.

**Cost per command.** Small prompt, small output, but every keystroke-triggered re-parse multiplies it.
Parse on submit, never on input. Prompt caching (§4) makes the category list nearly free after the first
call of the day; a per-day date in the system prompt would throw that away.

**Latency makes it feel worse than typing.** A command that takes two seconds to confirm is slower than
the four-field form it replaces. `effort: 'low'` and a streamed confirmation panel both help; if it still
loses to the form, that is the honest signal that this feature is for voice and for phones, not for
someone at a keyboard.
