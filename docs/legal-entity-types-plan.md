# Legal entity types: taxing a partnership, a sole proprietor and a non-profit correctly

> **Status: seven of nine phases built; two gated on the advisor.** Phases
> 1–4, 6, 7 and 9 are built — the `legal_entity` dimension, the sole-proprietor
> and AOP routing, the individual/AOP s.113 minimum tax, the commission/s.233
> account, the small-company rate, and the platform form. See
> `SoleProprietorReturnPackTest`, `CorporateReturnPackTest`,
> `PersonalReturnPackTest`, `PersonalTaxServiceTest` and `CompanyProfileTest`.
> **Phase 5 (non-profit) and phase 8 (LLP) are deliberately not built** — each
> needs a §7 answer and building either blind would ship a wrong rate as
> authoritative. The per-phase state is in §6.
>
> **The tax treatments below are researched, not authoritative.** The phases
> already built proceeded on researched values pending the advisor's
> confirmation (§7 Q1, Q2, Q4, Q5, Q6); every rate, schedule and exemption is
> still for a tax advisor to confirm before a company relies on it — getting an
> AOP's rate or an NPO's exemption wrong is worse than the honest binary the
> application had. §7 lists exactly what to confirm; Q3 and Q7 are what remain
> blocking.

## 1. The problem: one field is doing two jobs

`Company::type` is `business | personal`, and it is the **only** thing that picks
a tax engine:

- `personal` → `PersonalReturnPack` → the individual slab schedules in
  `TaxScheduleSeeder` (salaried, non-salaried/AOP, rental, capital gains, export).
- `business` → `CorporateReturnPack` → a flat corporate rate (29%) plus the s.113
  minimum tax on turnover.

That single field is carrying two independent questions at once:

1. **What legal entity is this, for tax?** — individual, sole proprietor,
   partnership/AOP, company, non-profit. Each is taxed differently.
2. **What does the business do, and which modules does it need?** — already
   answered, well, by `profile` (services, trading, construction, …), which is a
   preset of modules and seeders and is freely extensible.

Because the two are fused into one binary, an entity whose *modules* say
"business" but whose *tax* is not the corporate flat rate has nowhere to sit. The
profile side is not the constraint; the tax side is.

## 2. What the data model already has, and already lacks

**Has** — more than the binary suggests. `TaxScheduleSeeder`'s `BUSINESS_BRACKETS`
is literally the *"Non-salaried individuals and AOPs"* schedule, seeded and
working. A personal account can already tag income as the `business` regime and
be taxed on it (`AccountForm` offers the regime; the Personal pack files it under
head 3000). So the arithmetic a partnership and a sole proprietor need is present.

**Lacks:**

- Any way to route a **business-type** entity to that non-salaried schedule — the
  schedule is reachable only from a personal account.
- Any concept of **tax exemption** — there is no flag an NPO could carry, and
  `CorporateReturnPack` has no branch that produces nil.
- The s.113 **minimum tax on individuals and AOPs** — the Personal pack computes
  no minimum tax at all, so a high-turnover sole proprietor or AOP filed through
  it would understate.
- A **legal-entity field** distinct from `type`. `type` is also create-only (it
  seeds the chart), which is correct for the chart but wrong as the home for a
  tax classification one might reasonably correct.

## 3. The headline entities, and where each lands today

| Entity | Modules it needs | Correct tax | Now |
|---|---|---|---|
| **Sole proprietorship** | business (invoicing, inventory) | individual slabs on business income | **built (phase 2)** — a `sole_proprietor` business routes to the slab-business pack (business modules + individual tax) |
| **Partnership / AOP** | business | the non-salaried/AOP slab schedule (seeded) | **built (phase 4)** — an `aop` business opens the same slab-business pack, with its own label and filing note |
| **Non-profit / NPO** | bookkeeping | exempt (s.2(36)/100C), s.113 carve-outs | **not built (phase 5)** — corporate pack → 29% + minimum on an exempt entity; an `ngo` *profile* exists but sets up operations only, not exemption |
| **Commission shop / agent** | business | ordinary business income; withholding s.233 | **built (phase 6)** — a `commission` income regime (business head, slab-taxed) and s.233 withholding itemised on account `1605` |

## 4. The decision: a `legal_entity` dimension, separate from `profile`

Add one field — `companies.legal_entity` — orthogonal to both `type` and
`profile`, and let **it**, not `type`, choose the tax engine. `type` stays what it
is (the chart-of-accounts switch, create-only); `profile` stays the module preset.
`legal_entity` is the tax classification and is the one of the three a person may
legitimately correct (reclassifying a sole prop as an AOP changes no books).

Values and the engine each selects:

| `legal_entity` | Tax engine |
|---|---|
| `individual` | Personal pack, individual salaried/other schedules |
| `sole_proprietor` | Personal pack, **business regime** + s.113 minimum once added |
| `aop` (partnership firm) | a return pack on the non-salaried/AOP schedule + s.113 |
| `company` | Corporate pack (today's behaviour) — the default |
| `small_company` | Corporate pack at the **reduced rate** (s.2(59A), 20% in recent Acts), minimum tax as normal |
| `non_profit` | Corporate/AOP figures computed but **marked exempt**, nil carried, s.113 carve-out applied |

Defaulting `legal_entity` to `company` for existing `business` rows and
`individual` for `personal` rows reproduces today's behaviour exactly — this is
additive, and a company that never sets the field sees no change.

The split that unblocks the sole proprietor: modules come from `profile`
(business), tax comes from `legal_entity` (`sole_proprietor` → Personal engine).
The two stop being forced to agree.

### 4.1 The rule for adding a value, and the other entities it admits or rejects

A `legal_entity` value earns its place only when the **tax engine or rate
differs** from one already listed. A value that would route to an existing engine
with no change is a label, not an entity, and belongs as display text, not a
branch. On that test:

**Admitted** (a real difference):

- **`small_company`** — a *company* (corporate pack), but s.2(59A) sets a reduced
  rate for one that qualifies (incorporated after a cut-off, paid-up capital and
  turnover under the caps, not carved out of an existing business, employee count
  under the limit). Many of this application's own customers qualify, and the
  only difference from `company` is the rate — which is **already an editable
  worksheet field**, so this is the cheapest value to add: it just defaults
  `tax_rate` to the small-company rate.
- **`llp`** — a Limited Liability Partnership (LLP Act 2017) is a distinct legal
  form whose tax treatment (AOP schedule vs company) is **§7's to confirm**; added
  as its own value precisely so the answer has somewhere to land rather than being
  guessed into `aop` or `company`.

**Rejected** (same engine as one already listed — a label, not a new entity):

- **Single Member Company (SMC)** — a company; taxed as `company`. (This
  installation's own tenant, "ERBIUMTECH (SMC-Private) Limited", is one, and the
  Corporate pack already serves it.)
- **Private vs public limited company** — both `company`, same rate barring the
  small-company test above.
- **Registered partnership firm** — an AOP; `aop`.
- **Trust, society, s.42 non-profit company** — all `non_profit`; the exemption
  fields in §5 carry the approval, and a sub-form name is display text, not a
  branch.

**Out of scope**, recorded so the omission is a decision: foreign companies, the
permanent establishment of a non-resident, and government bodies — none is this
SME application's market, and each carries treaty and withholding machinery a
profile switch should not pretend to cover.

## 5. Schema

- `companies.legal_entity` — **built.** String, nullable, indexed; null reads as
  the type-derived default (`company` for business, `individual` for personal) so
  no backfill was required and the column shipped dark.
- `companies.tax_exempt_ref` / `tax_exempt_approved_on` — **not built (phase 5).**
  The NPO's approval under s.2(36)/100C, to be recorded the way
  `invoices.commissioner_approval_ref` already records a sales-tax permission:
  printed on the return pack, relied on only when present, logged.
- A **s.233 commission** withholding section account (`1605`) beside the
  `1601–1604`, and a `commission` income regime label — **built (phase 6).** The
  regime declares under the business head and is slab-taxed; it has no schedule of
  its own but is a declared borrower of the business schedule
  (`TaxRegimes::SCHEDULE_ALIASES`). Income account `4400` carries it.

## 6. Phasing

Tax correctness lands behind a flag, entity by entity, because a wrong rate shown
as authoritative is worse than the current honest two-case model.

| Phase | Work | Risk | State |
|---|---|---|---|
| **1** | `legal_entity` column + accessor, defaulting to the type-derived value; no behaviour change | none | **built** |
| **2** | **Sole proprietor** — route a `sole_proprietor` business-type company to the Personal engine for its return pack, so business modules and individual tax coexist | medium — crosses the module/tax split for the first time | **built** — `SoleProprietorReturnPack` (service + page), gated on `Company::isSoleProprietor()` |
| **3** | **s.113 minimum tax for individuals/AOPs** in the Personal engine, since phase 2 now files real business turnover through it | medium — changes an existing computation | **built** — greater-of slab vs turnover minimum in the sole-proprietor pack, threshold a worksheet field |
| **4** | **Partnership / AOP** — a return pack variant on the non-salaried schedule (the schedule exists; this routes to it) | medium | **built** — `aop` shares the sole-proprietor engine (same schedule) via `Company::isSlabTaxedBusiness()`, with its own label/footnote |
| **5** | **Non-profit** — the exemption fields, a nil/annotated return, the s.113 carve-out | medium — legal, needs §7 answers | **not built — blocked on §7 Q3** (an `ngo` profile exists for operations, no tax treatment) |
| **6** | **Commission** — the `1605` s.233 account and the income-regime label | low | **built** — `commission` regime (business head, slab-taxed, borrows the business schedule), `1605`/`4400` seeded |
| **7** | **Small company** — a `small_company` value that defaults the Corporate pack's rate to the s.2(59A) reduced rate; qualification stays the operator's to assert | low — the rate is already a field | **built** — `Company::isSmallCompany()` defaults the worksheet to the reduced rate |
| **8** | **LLP** — route `llp` to whichever engine §7 confirms (AOP schedule or company) | low once confirmed | **not built — blocked on §7 Q7** |
| **9** | Platform panel: `legal_entity` on the company form, and the return pack each entity opens | low | **built** — the field is on `CompanyForm` (business only); each entity's `canAccess()` opens the right pack |

Phases 2–8 are independent once phase 1 lands — build only the entities a real
customer needs, in any order. The two left (5, 8) are each held by one §7
answer, not by effort.

## 7. What to confirm — for the tax advisor

**Q3 and Q7 still block phases 5 and 8 — they must be answered before those are
built. Q1, Q2, Q4, Q5 and Q6 were proceeded on using researched values and now
need confirming against the live figures, not awaiting an answer to start.**

1. **AOP rates.** Is `BUSINESS_BRACKETS` (the non-salaried/AOP schedule) the
   current, correct table for a partnership for the tax year in question, and does
   the 10% surcharge over 10,000,000 apply as the seeder comments assume?
2. **s.113 minimum tax.** The turnover threshold and rate at which it binds an
   individual and an AOP (not only a company), and which turnover counts.
3. **Non-profit.** The exemption's actual conditions (approval under s.2(36)/100C,
   the 100C regime), whether a return is still filed, and the precise s.113
   carve-out — this is the one with real legal exposure.
4. **Sole proprietor.** Confirmation that business income of a sole proprietor is
   assessed on the individual schedule (not a separate one), so phase 2's routing
   is right.
5. **Commission.** Whether commission income needs any treatment beyond ordinary
   business income plus s.233 withholding.
6. **Small company.** The current s.2(59A) definition and the reduced rate for the
   tax year — the paid-up-capital, turnover and employee caps, the incorporation
   cut-off, and the anti-splitting condition — so phase 7 defaults the right rate
   and the form can state what a company must meet to claim it.
7. **LLP.** Whether a Limited Liability Partnership (LLP Act 2017) is assessed as
   an AOP on the non-salaried schedule or as a company — the answer decides which
   engine phase 8 routes `llp` to.

## 8. What breaks silently if this is done wrong

- **A default that is not today's behaviour.** If `legal_entity` does not default
  to `company`/`individual` exactly matching the current `type` split, every
  existing company's return pack changes the day the column ships. The null-reads-
  as-derived rule in §5 is what prevents that; a test must pin it.
- **Minimum tax applied to the exempt.** s.113 has carve-outs; adding it (phase 3)
  without the NPO exemption (phase 5) would tax an exempt entity's turnover. The
  phase order matters only here — do not ship 3 for an NPO before 5.
- **Reclassification rewriting the chart.** `legal_entity` must *not* touch the
  seeded chart (that is `type`'s job, create-only). Keeping the two separate is
  what makes `legal_entity` safe to change after provisioning.
