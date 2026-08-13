# CRMS: plan

> **BUILT — every phase, 0 through 9.** Four modules: `crm` (leads, pipelines,
> deals, activities, next actions, targets, reports), `quotations`, `support` and
> `campaigns`. Around 95 feature tests across `CrmLeadTest`, `CrmPipelineTest`,
> `CrmLeadCaptureTest`, `CrmQuotationAndHandoffTest` and
> `CrmSupportAndCampaignTest`; full suite 1846 passing.
>
> **§1's bet holds, and is tested.** `crm` requires nothing: leads and deals work
> with `invoicing` unlicensed, and conversion is absent rather than broken.
> `quotations` requires `invoicing` (§2 — a quote that can never convert is a PDF
> generator) and `campaigns` requires `crm`; `support` requires nothing.
>
> **§10's seven prohibitions all hold**, each with a test: no second customer
> table, no second revenue number, no journal entry from a quote or a won deal, no
> automatic invoice, no automatic commission, no portal, no mail server.
>
> **Phase 5's FBR block, resolved narrowly rather than dismissed.** §13 said quote →
> invoice was blocked because a reported invoice may only be cancelled within 72
> hours. The resolution: conversion produces a **draft** invoice and stops. Issuing
> — which transmits — stays the deliberate act it already was in Invoicing. **The
> credit-note gap is real and remains Invoicing's**; nothing here brings it closer,
> and nothing here papers over it.
>
> Two deliberate narrowings, each argued at its call site: `lost_reason` was free
> text in phase 1 and is now the `lost_reasons` table §3 specified, with the text
> column kept beside it; and renewals are asked period-by-period through
> `RecurringInvoice::coversPeriod()` rather than through a `next_issue_date` column,
> because no such column exists and adding one would be the second answer to "when
> does this renew" that §3 refuses.
>
> Mechanics common to any new module are in `docs/new-module-checklist.md`; the HR
> side is `docs/hrms-plan.md`. This document decides what the customer-facing
> modules are and, more importantly, what they must not duplicate.

Three findings shape it:

1. **A customer record already exists.** `Invoicing\Contact` has
   `kind = customer|supplier|both`, payment terms with a `dueDateFor()` rule,
   NTN/CNIC, a bank, and named people in `contact_persons` with a
   one-primary-per-contact invariant and a `correspondenceEmail()` rule. A CRM
   that adds its own "Account" table gives this application two answers to "who is
   the customer", and the invoice will disagree with the pipeline.
2. **Everything after the sale is built; nothing before it is.** Invoices
   (`draft → issued → partially_paid → paid → void`, sale and purchase),
   recurring invoices, an `invoice_events` timeline, tax rates, multi-currency
   with exchange rates, project-linked invoices, ageing, and client billing
   assembled from payroll. There is no lead, no opportunity, no pipeline stage,
   no logged call, and — the omission that matters most day to day — **no next
   action**.
3. **A client portal was refused on purpose.** The `create_invoice_events_table`
   migration says it: there is no `viewed` event because that "needs a client
   portal or a tracked email, and this application has neither by decision.
   Inventing a column that never fills would be worse than its absence." A CRM
   plan must respect that decision or argue against it explicitly (§7), not
   smuggle a portal in as a feature.

## 1. The party decision

Where does a prospect live before it is a customer? This is the only
architectural choice in the plan; everything else follows from it.

| Option | What it means | Verdict |
|---|---|---|
| **A. `crm` requires `invoicing`, prospects are `Contact` rows** | one party table, zero new concepts | **rejected** — a prospect is not a contact you can invoice, `kind` grows a `prospect` value that every invoicing query must now exclude, and CRM becomes unsellable without Invoicing *and* Accounting |
| **B. Move `Contact` to Core (or a new `parties` module)** | the cleanest long-term shape | **rejected for now** — see below |
| **C. `crm` owns `Lead`; an opportunity belongs to exactly one of a lead or a contact** | prospects are CRM's, customers stay Invoicing's | **chosen** |

**Why not B, even though it is architecturally right.** The namespace move itself
is nearly free: `ModuleMap` already aliases `Contact` as `App\Models\Contact`, so
moving the class changes the map's *value* and existing `custom_fields.model_type`,
`comments.commentable_type` and `table_views.resource` rows keep resolving —
exactly what the alias scheme was built for. What is not free is the permission
group. `ContactView / ContactCreate / ContactUpdate / ContactDelete` are seeded in
group **`Invoicing`**, and `PermissionSeeder` matches on `['name', 'group']` while
the `permissions` table carries **no unique index** — so changing the group
silently inserts a duplicate `ContactView` row, leaving existing roles pointed at
the old one. That is a data migration with a landlord-wide blast radius, and it is
not worth spending to ship a CRM. Revisit if a customer ever wants CRM without
Invoicing.

**How C works.** `crm` requires nothing. A lead is captured, worked and either
converted or lost entirely inside CRM. Conversion — the moment a prospect becomes
somebody you can invoice — creates a `Contact`, and that action is hidden when
`modules()->enabled('invoicing')` is false. The precedent is recorded in
`ModuleBoundaryTest`: Invoicing → Projects is a *guarded* coupling, not a
declared requirement, so "an invoice may name the engagement it belongs to" while
Invoicing stays sellable to a company that runs no projects. Same shape,
same reasoning.

`opportunities` therefore has nullable `lead_id` and nullable `contact_id` with
**exactly one set**, asserted in `booted()` and tested — the way
`ContactPerson` asserts one primary per contact rather than hoping. A repeat deal
against an existing customer has `contact_id`; a new-business deal has `lead_id`
until it converts, and conversion fills `contact_id` while keeping `lead_id` as
the origin. Sales history is the reason to keep both: "where did this customer
come from" is a lead-source question asked years later.

## 2. Module map

| Module | Owns | `requires` | Guarded (soft) | Company profiles |
|---|---|---|---|---|
| `crm` | leads, opportunities, pipelines and stages, activities, next actions, win/loss, targets | — | `invoicing` (conversion, deal→invoice), `employees` (owner as employee), `projects` (won deal → project) | every business profile |
| `quotations` | quotes, versions, validity, quote → invoice | `invoicing` | `inventory` (product lines), `crm` (quote from an opportunity) | services, software house, trading, manufacturing |
| `support` | tickets, categories, SLA clocks | — | `invoicing`, `projects`, `crm` | software house, services |
| `campaigns` | segments, sends, consent | `crm` | — | trading, manufacturing, services |

`crm` requires nothing on purpose (§1). `quotations` requires `invoicing` because
a quote's whole point is becoming an invoice, and a quote that can never convert
is a PDF generator.

**On the profile column.** Every module must appear in at least one entry of
`config/company_profiles.php` or `CompanyProfileTest` fails —
`docs/new-module-checklist.md` §1a. Three of the four rows above are
uncontroversial; the fourth is worth stating because it is a judgement, not a
derivation:

- **`crm` goes to every business profile, including `bookkeeping`.** That looks
  wrong at first — a bookkeeping-only company has no sales pipeline. But §1's
  whole argument is that `crm` requires nothing and must be sellable to a company
  with neither Invoicing nor Accounting, and phases 1–4 are explicitly "a usable
  CRM on their own". A bookkeeping practice has clients it is pitching to. If
  `crm` is genuinely unsellable there, then §1's independence is theoretical and
  the requirement should be declared instead.
- **`support` is not for a trading company.** Tickets with SLA clocks are what a
  company selling ongoing service commits to. A distributor's after-sales problem
  is a return, which is Invoicing's, not a ticket.
- **`campaigns` is not for a staffing company.** Its outreach is to a handful of
  named client contacts, which is `activities`, not a segment and a send.

The `personal` profile takes none of these: a household has no customers.

## 3. `crm`

```
pipelines            id, name, is_default, is_active
pipeline_stages      id, pipeline_id, name, sort, probability_pct,
                     is_won, is_lost, rot_after_days
lead_sources         id, name, is_active, sort
leads                id, company_name, person_name, title, email, phone, whatsapp,
                     lead_source_id, owner_employee_id, status, rating,
                     estimated_value, currency_code, city, notes,
                     converted_contact_id, converted_at, lost_reason, lost_at
opportunities        id, title, pipeline_id, pipeline_stage_id, lead_id, contact_id,
                     owner_employee_id, amount, currency_code, exchange_rate,
                     probability_pct, expected_close_on, closed_on,
                     outcome, lost_reason_id, project_id, source_quotation_id
opportunity_stage_history  id, opportunity_id, from_stage_id, to_stage_id,
                     moved_by, moved_at, days_in_stage
activities           id, subject_type, subject_id, kind, direction, subject_line,
                     body, occurred_at, duration_minutes, outcome,
                     employee_id, created_by
next_actions         id, subject_type, subject_id, title, due_on, due_at,
                     assignee_employee_id, completed_at, completed_by, snoozed_until
lost_reasons         id, name, is_active, sort
sales_targets        id, employee_id, period_start, period_end, target_amount,
                     currency_code, kind
```

**Stages are rows, not an enum.** Every company renames them, and a config array
means a rename is a deploy. `probability_pct` on the stage gives a weighted
forecast without asking a salesperson to guess twice; `is_won` / `is_lost` mark
the terminal stages so reports do not pattern-match on names. `rot_after_days`
powers the only pipeline report that changes behaviour: deals that have not moved.

**`activities` is polymorphic over Lead, Contact and Opportunity** — so its
`subject_type` needs `ModuleMap` morph entries for both new models, and
`Contact`'s existing `App\Models\Contact` alias is reused unchanged.
`enforceMorphMap()` throws for anything missing, which is the intended safety net.

**Corrected:** an earlier draft said to give the new models short aliases
(`lead`, `opportunity`). That fails CI —
`ModuleCoverageTest::test_morph_map_aliases_are_the_legacy_class_names` asserts
`App\Models\{ClassBasename}` unconditionally, with no exemption for models that
never existed there. Write `'App\Models\Lead'` and `'App\Models\Opportunity'`.
`docs/new-module-checklist.md` §4 has the reasoning and the same correction.

*Rejected:* reusing `comments` for activity logging. A comment is a discussion
thread on a record; a call at 14:20 that lasted nine minutes and ended in "send
pricing" is a dated event with a duration and an outcome. Comments stay available
on the same records for internal discussion — they already work on any model.

**`next_actions` is the feature that makes the difference** between a CRM people
use and a data-entry chore. One rule: **an open opportunity with no next action is
surfaced as a problem**, on the board and in a digest. Everything else in a CRM is
recording the past; this is the only part that changes what happens tomorrow.

`activities` and `next_actions` are deliberately separate tables. A completed
activity is history and never changes; an action is a mutable intention with a due
date, an assignee and a snooze. Cramming both into one table gives every list
query an `is_done`-plus-`due`-plus-`occurred` filter that is wrong somewhere.

**Leads have to arrive from somewhere, and today the only way in is typing.**
§13 flags spreadsheet import as undesigned; the sharper gap is that there is no
capture channel at all — no form, no endpoint. A CRM whose leads are all
hand-keyed records the ones somebody remembered to key.

The precedent is already in this codebase and is already cited by this plan:
`/status/{company}/{token}` is unauthenticated, token-gated, and additionally
gated by a per-company setting (§7). A lead-capture endpoint is that exact shape
and invents nothing:

```
POST /leads/{company}/{token}    -> creates a lead with lead_source = 'web'
```

Four constraints, each of which is a way this becomes a spam sink otherwise:

- **Two gates, not one**, exactly as the status page has: the token *and* a
  per-company setting, so a leaked token can be closed without a deploy.
- **Rate limited per token**, and rejected silently rather than 429 — an endpoint
  that says how it is throttled tells a bot how to pace itself.
- **Fixed field list**, mapped to `leads` columns. Not a JSON blob: a payload
  nobody validates becomes a column nobody can query.
- **Never converts.** It creates a lead, and a person qualifies it. That is
  §10's rule, and a public endpoint is precisely where it would be tempting to
  break it.

**Deduplication ships with it, not after it.** §13 called dedup "phase 3.5 at
the latest", which was written when every lead was typed by a person who might
notice. An open endpoint removes that. Match on email, phone and company name;
merge activities and next actions; keep the older record's id.

**Built, with two deviations from this section's letter, both deliberate:**

- **There is no `owner_user_id` fallback.** This section says the owner field "falls
  back to the landlord user id" when `employees` is unlicensed. Implemented as
  written, that is two columns for one concept and two answers to "who owns this
  lead", which every report would then have to coalesce. Instead
  `owner_employee_id` is simply not offered when the module is off, and
  `leads.created_by` — a soft user reference, the `invoice_events.caused_by` shape —
  answers ownership. Same fallback, one column per concept.
- **`lost_reason` is free text at this phase, not `lost_reason_id`.** §3 makes
  `lost_reasons` a table because win/loss by reason is worth more than the forecast —
  but that report is phase 4, and shipping the table now with nothing reading it is
  the column-that-never-fills the `invoice_events` migration refused. Phase 4 adds
  `lost_reason_id` beside the text column and backfills.

The `Sales` navigation group is CRM's own rather than part of "Invoicing &
Inventory": the two answer different questions, and folding them together would show
the group to a company that licensed CRM *without* Invoicing, which §1 requires to be
possible. `NavigationGroupsTest` records the choice.

**Ownership is an employee, not a user.** `owner_employee_id` throughout, so
`EmployeeAccess` scoping applies unchanged: a sales manager sees their downline's
pipeline and no further, using the same BFS every other resource uses. Guarded —
with `employees` unlicensed the field falls back to the landlord user id, the way
`invoice_events.caused_by` already holds a soft user reference.

**Currency.** `Currency` and `ExchangeRate` exist and invoices already carry a
currency. An opportunity stores its own currency and the rate used, because a
forecast in mixed currencies has to be summed at *some* rate and a rate that
moves silently rewrites last quarter's forecast.

**Renewals belong to `crm`, and this is where that gets decided.** §13 says to
"name the CRM surface renewals and link to the recurring invoice", and until now
that requirement lived only in the risks section — which is how a requirement
gets built by accident, or not at all.

It owns no table. A renewal is a **view over `recurring_invoices`**, which
already carry the schedule and the next issue date, surfaced next to the pipeline
because renewing is a sales activity and losing one is a lost deal. What `crm`
adds is what Invoicing has no opinion about: an owner, a next action, and the
fact that a renewal is *at risk*.

Three rules keep it from becoming a second subscription system:

- **No renewal table.** The recurring invoice is the record. A parallel one would
  be a second answer to "when does this renew", and the invoice would win.
- **Guarded on `invoicing`.** With Invoicing unlicensed the surface is absent
  rather than empty — there are no recurring invoices to be a view of.
- **`beneficiary_subscriptions` is not this.** That table is what *we* pay for.
  Two things called subscriptions in one codebase will be confused at least once,
  which is exactly why §13 asked for the name.

Losing a renewal is recorded as an opportunity with `is_lost`, so churn shows up
in win/loss (§8) alongside new business rather than in a report of its own.

Phase 6, with the other Invoicing hand-offs.

**Targets and commission — the HR join.** `sales_targets` per employee per period
answers attainment. Paying it does **not** need new machinery: `pay_components`
already makes an earning a row, so a commission is a component amount on a
payslip, entered or imported after approval. Do **not** compute commission
automatically from won deals into payroll: the first disputed deal would become a
payroll incident, which is the same reason `performance` ratings do not touch pay
(`hrms-plan.md` §4.5). The CRM produces the number and a human approves it.

**Won deal → the rest of the system.** Three optional, guarded follow-ons, each
one action a human takes:

- `invoicing` on → raise a draft invoice from the deal (or from its quote).
- `projects` on → create a project and set `opportunities.project_id`; invoices
  already link to projects.
- `quotations` on → the accepted quote is the invoice's source.

None automatic. A won deal is a sales fact; an invoice is a legal document.

## 4. `quotations`

```
quotations       id, number, contact_id, lead_id, opportunity_id, currency_code,
                 exchange_rate, issue_date, valid_until, status, version,
                 supersedes_id, subtotal, tax_total, total, terms, notes,
                 accepted_at, declined_at, decline_reason, invoice_id, pdf_path
quotation_lines  id, quotation_id, product_id, description, quantity, unit_price,
                 discount_pct, tax_rate_id, line_total, sort
```

`draft → sent → accepted → declined → expired → superseded`.

**A quote never touches the ledger.** Not one journal entry, not a pending one. It
is an offer; nothing has happened. The ledger involvement begins when it converts
to an invoice, and `InvoiceService` already knows how to do that correctly. This
is the single most important sentence in this section, because a quote that
accrues revenue is an audit finding.

**Versioning by supersession, not by edit.** Revising a sent quote creates version
2 pointing at version 1 via `supersedes_id`, and the customer's copy of v1 stays
reproducible. A quote is a document somebody has in their inbox; editing it in
place makes the system disagree with that inbox.

`valid_until` expires a quote on a schedule (transition, one notification, not a
daily nag). Lines reuse `TaxRate` and, when `inventory` is on, `Product` — same
line shape as `invoice_lines`, so conversion is a copy rather than a translation.

## 5. `support`

```
ticket_categories  id, name, default_priority, sla_response_minutes,
                   sla_resolution_minutes, is_active
tickets            id, number, contact_id, contact_person_id, project_id,
                   category_id, subject, description, channel, priority, status,
                   assignee_employee_id, opened_at, first_responded_at,
                   resolved_at, closed_at, reopened_count, satisfaction_rating
ticket_replies     id, ticket_id, body, is_internal, author_employee_id,
                   author_name, created_at
```

`new → open → pending_customer → resolved → closed`, reopenable.

SLA is measured, not enforced: `first_responded_at` and `resolved_at` against the
category's minutes, with breaches reported. A system that refuses to close a
ticket because an SLA elapsed helps nobody.

**Inbound email is out of scope.** Parsing a mailbox into tickets means an IMAP
poller, threading heuristics and bounce handling — a subsystem, not a feature, and
this application has no inbound mail path at all today. Tickets are created in the
panel or by an internal user on the customer's behalf, with `channel` recording
how the customer actually asked. `is_internal` replies are staff-only notes, which
matters the day a portal exists (§7).

## 6. `campaigns`

Last, and smallest.

```
segments        id, name, definition (json), is_active
campaigns       id, name, channel, subject, body, segment_id, scheduled_at,
                sent_at, status, created_by
campaign_sends  id, campaign_id, contact_id, lead_id, channel, status,
                sent_at, failed_reason
consents        id, subject_type, subject_id, channel, state, source, recorded_at
```

Two hard constraints, both external:

- **WhatsApp is template-gated.** Meta's Cloud API only permits pre-approved
  template messages outside a 24-hour customer-service window. `CloudApiWhatsAppSender`
  already carries a `template` config. A campaign that ignores this fails at the
  API, and repeated attempts risk the number. So the WhatsApp channel sends
  approved templates with variables, never free text.
- **Consent is a row, not a checkbox.** `consents` records channel, state, source
  and when — because "who agreed to this and when" is the only defensible answer
  when someone complains, and an `is_subscribed` boolean cannot answer it. Every
  send checks it; an unsubscribe writes a new row rather than flipping the old.

Bulk sending goes through the existing queue. This module is last because it is
the only one that can damage the company's reputation, and it is worth nothing
until there are leads and contacts to send to.

## 7. The client portal — out of scope, deliberately

`create_invoice_events_table` refused a `viewed` event for want of a portal or
tracked email, and this plan does not overturn that. It does record what
overturning it would take, so the next person does not have to re-derive it:

- A token-gated unauthenticated surface — the precedent exists and works:
  `/status/{company}/{token}` for project status, gated by its own per-company
  setting *in addition to* the module licence.
- A decision about what a customer may see: their invoices and payments, yes;
  `ticket_replies.is_internal`, never. That flag exists in §5 for this reason.
- Then, and only then, `invoice_events.viewed` and open-tracking become fillable
  columns rather than decoration.

Until someone asks for it, the honest position is the one the migration already
took.

## 8. Reports

- **Pipeline by stage** — count and weighted value, per owner and per pipeline.
- **Forecast** — weighted by stage probability, at the stored exchange rate, with
  a plain (unweighted) column beside it because sales directors want both.
- **Win/loss** — rate by source, by owner, by lost reason. Worth more than the
  forecast, and it is why `lost_reasons` is a table rather than a free-text box.
- **Rotting deals** — no stage movement in `rot_after_days`, no open next action.
- **Activity** — calls/meetings logged per employee per week: a management number
  that must be read as effort, not as performance.
- **Quote conversion** — sent → accepted, and time to accept.
- **Ageing and revenue** already exist in Invoicing and Accounting. Do not build a
  second revenue report that will disagree with the ledger.

## 9. Notifications

`EmailTemplate` + `TemplatedMail` for wording, the WhatsApp senders for the
channel Pakistani B2B actually answers, the landlord `notifications` table for
in-app. Template keys: `lead.assigned`, `opportunity.stage_changed`,
`opportunity.won`, `opportunity.lost`, `next_action.due`, `quotation.sent`,
`quotation.expiring`, `quotation.accepted`, `ticket.opened`, `ticket.sla_at_risk`,
`ticket.replied`.

Same rule as everywhere else here: notify on transitions. A daily digest of "your
open actions" is one message; per-action reminders are thirty.

## 10. What this module family must not do

1. **Not a second customer table.** Contact is the customer (§1).
2. **Not a second revenue number.** The ledger and Invoicing own money.
3. **Not a journal entry.** Neither a quote nor a won deal posts anything.
4. **Not an automatic invoice.** A human raises it.
5. **Not automatic commission into payroll.** A human approves the component.
6. **Not a portal** (§7).
7. **Not a mail server** (§5).

Six of those seven are places where a CRM would usually reach for automation. The
reason to refuse is the same each time: this application's ledger is the record,
and a sales pipeline is a set of intentions. Intentions must not write to the
record without a person in between.

### 10a. Where AI may and may not act

Nothing here is built. The position is fixed now because lead scoring and call
summarization are baseline in competing products, so this gets proposed sooner or
later, and the sentence immediately above is already the answer — a model's
output is an intention like any other.

| Allowed | Refused |
|---|---|
| Score or rank leads and open deals | Close, disqualify, or move a stage |
| Draft an `activity` summary from a call or meeting note for a human to save | Write an activity, or set a next action, unattended |
| Suggest the next action on a rotting deal | Raise a quote, an invoice, or a commission component |
| Draft outreach copy for a human to send | Send anything (§6 consent and template gating are unchanged) |

Two constraints beyond the rule. **Nothing leaves the tenant without the company
switching it on**, the same shape §6 already uses for WhatsApp. And **a score is
displayed as a suggestion, never stored as a fact** — carrying its model and
timestamp if persisted at all, because an unlabelled number in a pipeline table
becomes a decision the moment somebody sorts by it.

## 11. Phasing

| Phase | Work | Risk |
|---|---|---|
| **0** | **BUILT.** `crm` module skeleton per the checklist; morph aliases (`App\Models\Lead`, `App\Models\LeadSource`); permission groups `Lead` and `LeadSource` | none |
| **1** | **BUILT.** Leads, sources, owners, conversion to Contact (guarded on `invoicing`), plus lost/reopen with a required reason | low |
| **2** | **BUILT.** Pipelines and stages as rows, opportunities with the exactly-one-party rule asserted in `booted()`, stage history recording moves backwards with `days_in_stage` | low |
| **3** | **BUILT.** Activities and next actions, polymorphic over Lead/Contact/Opportunity, with "open deal, nothing planned" shown in red and in the rotting report | low |
| **3.5** | **BUILT.** `POST /leads/{company}/{token}` — gated twice, throttled silently, fixed field list, never converts — with deduplication shipping alongside it | low |
| **4** | **BUILT.** Pipeline by stage, weighted forecast at the stored rate, win/loss by source, owner and lost reason, rotting deals, activity as effort | low |
| **5** | **BUILT.** `quotations` with supersession versioning; conversion produces a **draft** invoice and stops — the narrow resolution of the FBR block, which is recorded rather than dismissed | medium — touches Invoicing |
| **6** | **BUILT.** Won-deal hand-offs to project and draft invoice, both guarded and both a human act; renewals as a view over `recurring_invoices`, owning no table | low |
| **7** | **BUILT.** `sales_targets` with attainment computed on read; commission stays a payroll component a human enters | low |
| **8** | **BUILT.** `support` — SLA measured and never enforced, internal replies that do not start the response clock and are never customer-visible | low |
| **9** | **BUILT.** `campaigns` — consent as append-only rows re-checked at send time, WhatsApp template-gated on the model, skips reported as their own figure | medium — external, reputational |

Phases 1–4 are a usable CRM on their own and can ship to a company that has
neither Invoicing nor Accounting.

## 12. Testing

Beyond the eight `Module*` tests:

1. An opportunity with neither party, or with both, is rejected.
2. Converting a lead creates a Contact, sets `converted_contact_id`, and keeps the
   lead readable as the origin.
3. With `invoicing` unlicensed: leads and opportunities work; conversion is not
   offered (`ModuleDegradationTest`).
4. A won opportunity posts **no** journal entry and creates **no** invoice by
   itself.
5. Weighted forecast uses the stage's probability and the stored exchange rate,
   and does not change when today's rate does.
6. Stage history records every move with `days_in_stage`; a move back is recorded,
   not overwritten.
7. `EmployeeAccess`: a sales manager sees their downline's opportunities and no
   others, including in filter option lists.
8. A quote accepted then revised produces v2 with `supersedes_id`, and v1 remains
   reproducible.
9. Quote → invoice copies lines, tax rates and currency, and the invoice — not the
   quote — is what posts.
10. An expired quote cannot be accepted.
11. A send to a contact with a revoked consent row does not go out.
12. SLA breach is reported and does not block closing the ticket.
13. `is_internal` ticket replies are never returned by any customer-facing query
    (guards the portal decision in advance).
14. Activity `subject_type` round-trips through the morph map for all three
    subject types — and the aliases are `App\Models\Lead` /
    `App\Models\Opportunity`, which `ModuleCoverageTest` already asserts (§3).
15. **Lead capture is gated twice** (§3): a valid token with the company setting
    off creates nothing, and an invalid token with the setting on creates
    nothing. Both must be true — a single gate cannot be closed without a deploy.
16. **The capture endpoint never converts.** A posted payload creates a lead and
    no Contact, whatever it contains. This is §10's rule at the one door where
    breaking it would be most tempting.
17. **Throttling is silent.** A rate-limited request is rejected without
    disclosing the limit, so the response to an over-limit caller is
    indistinguishable from an ordinary rejection.
18. **Dedup keeps the older record's id** and moves activities and next actions
    onto it — asserted by counting activities before and after a merge, because
    the failure mode is losing history rather than losing the duplicate.
19. **A renewal owns no row.** The renewals surface returns a view over
    `recurring_invoices`; with `invoicing` unlicensed it is absent rather than
    empty (`ModuleDegradationTest`), and a lost renewal is an opportunity with
    `is_lost` so it appears in win/loss.

## 13. Risks and open questions

- **The party split is the bet.** If a customer ever wants CRM without Invoicing
  *and* wants full customer records, option B (moving `Contact`) comes back — and
  it comes back with the permission-group data migration described in §1. Nothing
  in phases 1–4 makes that harder, which is why they are first.
- ~~**`beneficiary_subscriptions` is not renewals.**~~ **Settled** — renewals are
  a `crm` view over `recurring_invoices`, owning no table of their own, in phase
  6. See §3. The warning stands: `beneficiary_subscriptions` is what *we* pay for
  and must never be confused with what a customer renews.
- ~~**Deduplication.**~~ **Settled** — it ships with the lead-capture endpoint in
  phase 3.5 rather than "at the latest", because an open endpoint removes the
  person who would have noticed the duplicate. Match on email/phone/company name,
  merge activities and next actions, keep the older record's id.
- **Three tables that are all a person who is not yet a record.** `leads`,
  `applicants` (`hrms §4.4`) and `contacts` each hold a name, an email, a phone,
  a source and notes, and each converts into something else. §1 rejected merging
  `contacts` for a specific reason — the `permissions` table has no unique index,
  so re-grouping `ContactView` silently duplicates it — but that argument does
  not reach `leads` and `applicants`, which are both new.

  **Decided: three tables, and not merged.** A lead is an organisation with a
  person attached; an applicant is a person with no organisation; a contact is a
  party the ledger can bill. They share a shape, not a meaning, and the join that
  would justify one table — "this lead is also a job applicant" — is a
  coincidence, not a relationship anybody queries.

  Recorded here rather than left implicit, because the day somebody notices the
  overlap, the cheap-looking fix is a `parties` table that Invoicing then has to
  reach into for something it already owns.
- **Stage probability is a company-level guess** applied to every deal. Fine at
  this scale; do not let it become a forecasting claim.
- **Multi-currency forecasting** is honest only because the rate is stored per
  opportunity. Anyone who "fixes" that to read live rates will silently rewrite
  history.
- **Activity volume** is the one table that grows with usage rather than with
  headcount. Not unbounded like `attendance_days`, but worth an index on
  `(subject_type, subject_id, occurred_at)` from the start.
- **Import.** Every CRM's first day is a spreadsheet import. Not designed here;
  `GnuCashImport` is the local precedent for how one should behave (dry-run
  preview, idempotent re-import). It shares the dedup built in phase 3.5 — an
  import is the other unattended way leads arrive.
- **FBR digital invoicing — an open blocker on phase 5.** Now researched and
  designed in **`docs/fbr-digital-invoicing-plan.md`**; read that before planning
  phase 5. The short version: mandatory digital invoicing applies to sales-tax
  registered persons, the deadlines were September and October 2025 and have
  passed, integration is only through PRAL or an FBR-licensed integrator, and a
  reported invoice may only be cancelled or edited within 72 hours.

  That last rule is what reaches this plan. Quote → invoice conversion produces
  invoices that must be transmitted, and once transmitted they lose the free
  `void()` this codebase currently offers — so the conversion cannot be designed
  as if an invoice raised in error is locally reversible. It is not, after 72
  hours, and the answer is a credit note that does not exist yet.

  It belongs to **Invoicing, not CRM**, so phases 1–4 are unaffected and remain
  shippable.
