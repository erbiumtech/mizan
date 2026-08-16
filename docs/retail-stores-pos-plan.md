# Multi-Store Retail, POS and Stock — Plan

**Status:** Not started
**Created:** 2026-08-14
**Covers:** stores and per-store stock (§1–§4), an offline-capable till (§5, §12), compliance (§6),
consolidated and per-store reporting (§7), scales / prep displays / tables (§8, §9), promotions and
loyalty (§10), an online storefront (§11)

Goal: let one company run many retail outlets from this application — a cloth shop, a bakery, a shoe
shop, a department store, a supermarket, a convenience store, a specialty shop, a discount store, a
warehouse club, an online shop — with a till that issues a compliant invoice, stock that is true per
outlet, and reporting an administrator can read **consolidated across all stores or one store at a
time**.

Three findings shape everything below, and the first two are the whole difficulty.

1. **Stock in this application has no location.** `stock_movements` carries product, type, quantity,
   unit cost, `remaining_quantity` and a date, and nothing else
   (`database/migrations/tenant/2026_07_16_120000_create_products_and_stock_movements_tables.php`).
   `InventoryValuationService::onHand()` sums every movement for a product, everywhere
   (`app/Modules/Inventory/Services/InventoryValuationService.php:16`). A second shop does not make
   that figure wrong in a way anyone can see — it makes it a company-wide total presented as if it were
   the shelf in front of you. **Nothing else in this plan matters until stock is per location.**
2. **The ledger has no dimension column.** `journal_entry_lines` is
   `journal_entry_id, account_id, debit_amount, credit_amount, description`
   (`2026_07_15_082837_create_journal_entry_lines_table.php`). A profit and loss per store therefore
   needs either a store on the line or a duplicated chart of accounts per store, and that fork decides
   how every report in Phase 4 is written. This plan takes the first (§2.2) and says why.
3. **Invoice numbers are derived by reading the last one.** `Invoice::nextInvoiceNumber()` finds the
   highest matching number with `orderByDesc('invoice_number')` and adds one
   (`app/Modules/Invoicing/Models/Invoice.php:137-141`). One accountant issuing invoices is fine. Four
   tills selling at once is a duplicate-number race, and an offline till cannot participate at all.
   Retail needs a per-store sequence and a reservation that does not read-then-write.

## What already exists and is reusable

Written down because the size of this plan depends on it — most of the accounting half is built.

- **Inventory posts to the ledger already.** `InventoryService::purchase()/sale()/adjust()` create the
  stock movement *and* the journal entry, and `InventoryValuationService` carries FIFO/LIFO/average per
  product (`products.valuation_method`, an enum of `fifo|lifo|average`). A POS sale is `sale()` with a
  location.
- **Invoicing is complete for a sale**: tax rates per line, credit notes with commissioner approval,
  multi-currency, payment settlement against A/R, and `InvoiceService` posts the double entry.
- **FBR digital invoicing has a driver layer and a submission log** (`fbr_status`, `fbr_irn`,
  `fbr_usin`, `fbr_qr_payload` on invoices; `fbr_submissions` with idempotency key, request/response
  payloads and outcome). Per `docs/fbr-digital-invoicing-plan.md`, **phase 1 is deliberately not built
  and nothing transmits**. Retail does not need a second integration — it needs a store's registration
  details attached to that one (§6).
- **The float pattern exists.** `PettyCashService` runs an imprest float: opening balance, vouchers
  out, closing, replenishment, and a month summary. A till session is the same shape with a count and a
  variance, and should be built as a sibling rather than a novelty.
- **Module mechanics are documented and enforced.** `docs/new-module-checklist.md` — registry entry,
  company profile, plugin, provider, `ModuleMap` (models/resources/pages/widgets/permission groups),
  policies, permissions. `ModuleCoverageTest` and `CompanyProfileTest` fail for anything missed. This
  plan does not restate any of it.
- **Row-level scoping has a precedent.** `App\Support\EmployeeAccess` + a `getEloquentQuery()` filter
  per resource is how manager/downline access works (`docs/employee-hierarchy-access-plan.md`). Store
  scoping is the same shape with a different resolver (§3).
- **The Reports explorer is generic.** Four kinds, per-report filters (`ReportPane::ASKS`), a record
  row, URL state. A store filter is one more ask; see `docs/reports-expansion-plan.md`.

## Not doing

- **A store per tenant.** Filament's tenant here is the Company, each with its own database. Making
  each shop a tenant would give every shop its own chart of accounts, its own customer list and its own
  fiscal years, and consolidation would mean querying across databases — the one thing this
  architecture cannot do. A store is a **dimension inside the tenant**, exactly as `invoices.project_id`
  already is (`2026_08_07_120000_add_project_to_invoices_table.php`).
- **A subclass per retail type.** The ten kinds in the request differ in three attributes, not in ten
  workflows: whether a product has **variants** (cloth, shoes: size × colour), whether it has a
  **shelf life** (bakery, supermarket: batch and expiry, waste), and whether it is **fulfilled rather
  than handed over** (online: pick, pack, ship, return). Everything else — till, stock, tax, reporting
  — is one implementation. Store *type* therefore selects capabilities and defaults, following the
  `config/company_profiles.php` pattern where a profile is a preset and never a restriction.
- **A separate front end.** No React/Vue POS. The till is a Livewire page in this panel (§5), because
  the alternative is a second application with its own auth, its own deploy and its own copy of the
  price list.
- **A promotions scripting language.** Phase 9 is a rule *table* — types, scope, priority,
  exclusivity, validity — evaluated deterministically. A formula or script per promotion is a
  programming language inside the till, with no tests and no way to explain a price to a customer at
  the counter. A discount this application cannot express is a manual line with a reason on it.
- **Certified weights-and-measures integration.** §8 reads weight from a scale and prices it. Legal
  metrology — a certified indicator, a sealed calibration, trade-approval marks — is a hardware
  certification exercise per jurisdiction and per device, and it is not something this repository can
  hold. The plan handles the two cases that need no certification: a scale-printed barcode (which is
  just a barcode) and a scale reporting weight to an agent, with the certified indicator still the
  legal display.
- **A payment-gateway abstraction.** Phase 10's storefront takes one gateway, chosen by the company,
  with the integration named. A provider-agnostic layer is a second product and every PSP breaks it
  differently; a second gateway is a second driver written when a second customer needs it.
- **Marketplace and channel integrations** (Daraz, Amazon, social catalogues). Each is somebody
  else's API and its own reconciliation story. The online channel here is this application's own
  storefront, and a marketplace is a later plan that would reuse the same order path.
- **Restaurant service depth.** Phase 8 does tabs, tables, and a prep display because a bakery-café
  needs them. Course timing, split-by-seat, waiter hand-helds and a reservation book are a hospitality
  product, and naming that boundary now is what keeps the till from becoming one by accident.

## §1 What a store is

`stores` (tenant): `code`, `name`, `type`, `channel` (`shop` | `online`), address, phone, `timezone`,
`currency_code`, `is_active`, `opened_on`, `closed_on`, plus the accounting handles a store needs of its
own — `cash_account_id`, `revenue_account_id`, `cogs_account_id`, `inventory_account_id`
(all nullable, defaulting to the product's or the chart's) — and the tax registration fields FBR wants
per outlet (§6).

`type` is one of the ten named in the request, and it drives a capability set rather than behaviour:

| Store type | Variants | Batch / expiry | Weighed | Tabs & tables | Prep display | Fulfilment |
|---|---|---|---|---|---|---|
| Cloth shop | **yes** (size × colour) | no | no | no | no | no |
| Shoe shop | **yes** (size × width) | no | no | no | no | no |
| Bakery | no | **yes** | **yes** | some (café side) | **yes** | no |
| Supermarket | some | **yes** | **yes** | no | no | some |
| Convenience store | no | **yes** | some | no | no | no |
| Department store | **yes** | some | no | no | no | some |
| Specialty store | some | some | no | no | no | some |
| Discount store | no | some | no | no | no | no |
| Warehouse club | no | some | some | no | no | some |
| Online retailer | inherits | inherits | inherits | no | **yes** (pick list) | **yes** |

Notes the table cannot hold: a department store's departments are a category tree with its own
reporting; a specialty store may want serial tracking; a discount store wants markdown reporting; a
warehouse club's membership is a contact requirement at the till, which is the same mechanism loyalty
uses (§10).

Six capabilities, then, each a flag on the store and each phased separately — variants and batch/expiry
(Phase 6), weighed items and prep/tables (Phase 8), fulfilment (Phase 10). A company that sells none of
them sees none of them, which is the point of expressing store type as capabilities rather than as ten
workflows.

## §2 The two architectural decisions

### 2.1 Stock is per location, and a transfer is two movements

`stock_movements.store_id` (nullable → backfilled to the default store → made non-null), and
`InventoryValuationService` takes a store: `onHand(Product, ?Store)`, `stockValue(Product, ?Store)`,
`averageCost(Product, ?Store)` — with `null` meaning the company, which is what today's callers mean
and keeps them working.

The cost pool is **per (product, store)**. A company-wide average across stores would let a shop that
bought cheap subsidise one that bought dear, and every gross-margin figure per store would be wrong in
a way that nets to zero at company level — invisible in the consolidated accounts, wrong in every
store's own. FIFO layers (`remaining_quantity`) are consumed within the store that holds them.

A **transfer** is a new movement type in both directions: out of A at A's cost, into B at that same
cost. It posts no profit; if the two stores use different inventory accounts it moves value between
them, and if they share one it posts nothing at all. Transfers need a state (`draft`, `in_transit`,
`received`, `discrepancy`), because stock that left A and has not arrived at B is real and is where
shrinkage hides. `stock_transfers` + `stock_transfer_lines` with `quantity_sent` and
`quantity_received`.

Also new movement types: **waste** (bakery, expiry), **count adjustment** (a stocktake's difference,
which must reference the count rather than being a loose adjustment), and **return** (customer,
back onto the shelf — note `InvoiceService` deliberately moves no stock on a credit note today
(`InvoiceService.php:215-222`), and a retail return has to, so it becomes an explicit movement with a
reason).

### 2.2 The store goes on the journal line, not into a second chart of accounts

`journal_entry_lines.store_id`, nullable, set by every posting that has a store.

The alternative — `4100-01 Sales — Gulberg`, `4100-02 Sales — DHA` — is what most small systems do and
it is a trap: the chart doubles per shop, the trial balance becomes unreadable, `ComparativeStatement`
and the ageing reports need per-store variants, and closing a store leaves dead accounts that must stay
for history. One column instead gives a per-store profit and loss from the **existing**
`FinancialReportService` with a `where` clause, and consolidation is the same query with the clause
removed. It also means a store dimension on *cash*, which is what makes a till reconcile.

Cost: every `JournalEntryService::create()` caller that has a store must pass it, and the ones that do
not (payroll, depreciation, a bank charge) leave it null and land in "unallocated" — which is a real
answer and must be visible in the reports rather than silently distributed.

## §3 Who sees which store

- `store_user` (or `store_employee`) pivot: which stores a person works in, and a `is_manager` flag.
- `App\Support\StoreAccess::accessibleStoreIds(User)` — request-memoised, mirroring `EmployeeAccess`.
  Privileged roles (Administrator, Accountant, CEO) see every store; a cashier sees theirs.
- Resource-level: `getEloquentQuery()` filters by `whereIn('store_id', StoreAccess::…)` on every
  store-scoped resource. The same trap as the employee work applies — a resource that forgets the
  filter is silently open, so the test asserts it per resource rather than trusting the trait.
- **New permissions**: `StoreView`, `StoreManage`, `PosOperate`, `TillReconcile`, `StockTransfer`,
  `StockCount`, `PriceOverride`, and per the checklist they go in `ModuleMap::PERMISSION_GROUPS` and
  the `PermissionSeeder`, plus each role in `RoleSeeder` (a Cashier role is new).
- **The selected store is not a permission.** It is a filter (§7). A cashier's *access* set is one
  store; an administrator's selection of one store must never be mistaken for a restriction, or the
  first support call is "the reports are gone".

## §4 The module boundary

A new module — key `retail` — per `docs/new-module-checklist.md`, with
`'requires' => ['accounting', 'invoicing', 'inventory']`, since a till without a ledger, an invoice or
stock is nothing. Then, and none of these is optional:

1. `config/modules.php` entry (an unmapped class is **silently ungated** in `canAccess()` and in
   `Gate::before`).
2. `config/company_profiles.php` — a **new `retail` profile** (and `trading` gains the module), or
   `CompanyProfileTest` fails for a module no profile recommends. The profile's chart of accounts must
   suit a retailer, and closure under `requires` is enforced.
3. `RetailPlugin` (discovery, unconditional) and `RetailServiceProvider` in `bootstrap/providers.php`,
   with **every policy registered explicitly** — module models never resolve by convention.
4. `ModuleMap`: models, resources, pages, widgets, permission groups; morph aliases in the
   `App\Models\{Class}` form, which `ModuleCoverageTest` asserts with no exemptions.
5. `NavigationTree`/`NavigationDomains`: a Retail branch. The rail is six domains and 68px; whether
   retail is its own domain or a branch of Sales is a design decision to take deliberately —
   `NavigationDomainsTest` pins the answer either way.

Whether **POS** is a separate module from **retail** is worth deciding up front: a company with one
shop and no till (an online-only seller) is a real customer, and so is a market stall that wants a till
and no multi-store reporting. Two keys (`retail`, `pos`) cost one extra registry entry and make both
sellable; one key makes the licence coarser. Recommendation: one module now, split only if a customer
asks — the checklist makes splitting later cheap, and `requires` cannot be un-declared once sold.

## §5 The till

A single Livewire full-page component, keyboard- and scanner-first. Not a Filament resource form: a
cashier types or scans, and every interaction must be one keystroke with no page transition.

- **Scan → line.** `product_barcodes` (product or variant, barcode, is_primary) — `products` has `sku`
  and no barcode today, and a supermarket item has several (case, unit, weighed label).
- **A sale is an `Invoice`**, with `store_id`, `kind = sale`, and a `channel`. (§9 adds one document in
  front of this for the stores that need a tab, a table or a prep queue — the invoice is then created at
  payment. Straight-serve retail keeps the path described here.) Reusing the invoice is
  the decision that keeps the ledger, the tax, the credit note and the FBR submission machinery — a
  separate `pos_sales` table would duplicate all four and drift.
- **Tenders**: `invoice_tenders` (method: cash / card / wallet / credit / voucher, amount, reference).
  A sale may be split across two, which the current single-payment settlement path cannot express;
  `InvoiceService::recordPayment()` is per amount, so a multi-tender sale posts one settlement per
  tender against the same invoice.
- **Walk-in customer.** `contact_id` is required on invoices today. A retail sale usually has no named
  customer, so either a per-store "Walk-in" contact (cheap, keeps the constraint, pollutes the contact
  list with one row per store) or a nullable `contact_id` with A/R replaced by cash at posting time
  (correct, touches `InvoiceService`'s posting paths). Take the second: a walk-in cash sale that lands
  in receivables is a receivable nobody will ever collect.
- **Numbering.** `store_sequences (store_id, kind, year, next_number)` incremented in a transaction with
  `lockForUpdate()`, and the number formatted `{STORE}-{YY}-{000001}`. This replaces
  `Invoice::nextInvoiceNumber()`'s read-the-highest approach **for store-issued documents only**; the
  accountant's manual invoices keep theirs, and the test that matters asserts two concurrent tills
  never collide.
- **Till session**: `till_sessions` (store, user, opened_at, opening_float, closed_at, counted_cash,
  expected_cash, variance, notes). Open with a float, close with a count; the variance posts to a
  cash-over/short account, exactly as the petty cash float does. **A session that is never closed is
  the normal failure**, so the report in Phase 4 lists open sessions by age.
- **Receipt**: an 80mm ESC-POS/HTML template, with the FBR QR payload when §6 is live. The PDF engine
  is already abstracted per engine (`docs/`-recorded Dompdf fallbacks), so a thermal template is a
  template, not an integration.
- **Refunds and exchanges** go through the existing credit note — plus the stock movement it currently
  refuses to make (§2.1) — and are gated by permission and by the 72-hour FBR rule (§6), not by a
  supervisor password.

## §6 Compliance, and the part that must be confirmed

> **Regulatory facts here are researched, not authoritative** — the same caveat
> `docs/fbr-digital-invoicing-plan.md` opens with, for the same reason: the controlling notifications
> have been replaced more than once in a year. Nothing here substitutes for a tax advisor.

What is knowable from inside this repository:

- The submission plumbing is built and dormant: `fbr_submissions` with an idempotency key, a driver
  string, request/response payloads and an outcome; `fbr_status`/`fbr_irn`/`fbr_usin`/`fbr_qr_payload`
  on the invoice; `FbrReconciliation` finds what was never accepted and what was never sent.
- **Retail adds a per-outlet identity to it.** Pakistan's POS regime for Tier-1 retailers is a
  *separate* scheme from general digital invoicing: each point of sale is registered and its invoices
  carry the outlet's identifiers and a verifiable QR. So `stores` needs `ntn`, `sales_tax_registration`,
  `pos_registration_no` and `fbr_environment` (sandbox/production), and the submission payload gains
  the store. **Confidence: the shape is safe; the field list, the fee and whether the two regimes have
  merged are for the advisor.** §9 of `docs/fbr-digital-invoicing-plan.md` is where those questions
  already live — add the POS ones there rather than starting a second list, and put §12's question
  (what may be issued while a till is offline) at the top of them.
- **The 72-hour rule is already built and enforced, which the till inherits.**
  `Invoice::isFbrLive()`, `fbrCorrectionWindowClosesAt()` and `fbrCorrectionWindowOpen()`
  (`app/Modules/Invoicing/Models/Invoice.php:220-257`) implement the window, and
  `InvoiceService::void()` refuses an invoice in flight, refuses one still correctable at FBR, and
  directs a closed-window correction to a credit note (`InvoiceService.php:875-900`). So the rule
  exists; what retail adds is the **counter experience** of it. A cashier facing a customer with
  yesterday's receipt cannot be shown an `InvalidArgumentException` — the till has to decide which of
  the three corrections applies *before* offering the button, and say so in a sentence. That is a UI
  obligation on an implemented rule, not new logic.

## §7 Reporting: all stores, or one

This is the request's last clause and it is a UI decision plus a filter, not a second report set.

**A store scope in one place.** `App\Support\StoreScope` — the selected store for this request, read
from a `#[Url]`-backed `store` parameter with the session as the fallback and **"All stores" as the
default**. One resolver, used by the dashboard, the report pane and the store-scoped tables, so the
answer to "which store am I looking at" cannot differ between two panels on the same screen.

**A store switcher in the topbar**, beside the company switcher, showing only accessible stores and
"All stores". It sets the URL parameter, so a link a manager sends opens on the store they meant —
the same reasoning that put the Reports filters in the query string.

**The dashboard.** Filament's stock `Dashboard` is registered today
(`AdminPanelProvider.php:208`) with `->widgets([])`, and each module discovers its own. Replace it with
a `RetailDashboard` (or add filters to a custom Dashboard page) so every widget reads `StoreScope`:

1. **Today, per store** — sales, transactions, average basket, gross margin, cash counted vs expected.
2. **All-store comparison table** — one row per store, this period vs last, with the consolidated total
   as the footer. The single most useful screen in this plan, and the reason the store went on the
   journal line: it is one grouped query.
3. **Stock alerts** — below reorder level, expiring within N days, negative on-hand (which must be
   impossible and will happen), transfers in transit over N days.
4. **Till exceptions** — sessions open too long, variances over a threshold, price overrides, refunds
   after N hours.

**Reports** (following `docs/reports-expansion-plan.md`, which owns the mechanics — a page, a
`SECTIONS` line, a `KINDS` line, an adapter):

- *Sales by Store* (and by hour of day, which is what staffing decisions need)
- *Sales by Product / Category / Department*, per store or consolidated
- *Gross Margin by Store and Product* — needs the per-store cost pool from §2.1
- *Stock on Hand & Valuation by Store*, reconciled to each store's inventory account
- *Stock Transfers* — sent, received, in transit, discrepancies
- *Stocktake Variance* — counted vs expected, valued
- *Waste & Shrinkage* — by store, by reason, valued (the bakery's daily number)
- *Till Sessions & Cash Variance*
- *Tender Mix* — cash vs card vs wallet per store
- *Refunds & Price Overrides* — an exception report, read for control rather than for insight
- *Store Profit and Loss* — the existing statement with a store filter, plus an "unallocated" column
  that must be shown, never spread
- *Slow-moving & Dead Stock* — no movement in N days, valued, per store

`ASKS` gains `store`, so every one of these gets its store picker for free, and the existing accounting
reports gain the same filter without changing their adapters.

## §8 Peripherals: scales, prep displays, tables

Three things the request names, and they are not one project — they are one *prerequisite* and three
thin layers on top of it. The prerequisite is in §9.

**Weighing scales, in the two forms that need no certification.**

1. **Scale-printed barcodes (the default, and no integration at all).** A supermarket or bakery scale
   prints a label whose barcode embeds the item code and either the weight or the price. The till does
   not talk to the scale; it *parses* the barcode. So `product_barcodes` carries a `kind`
   (`unit`, `case`, `weighed_template`) and a parse rule naming which digits are the item and which are
   the weight or price, plus the implied decimal. This is a string-parsing job with a table of rules and
   it covers the majority of weighed retail.
2. **A live scale, through a local agent.** A browser cannot open a serial port in any way this
   application should depend on — WebSerial is Chrome-only, needs HTTPS and a user gesture per device,
   and is not available on the tablets these tills will run on. So: a small local bridge on the counter
   machine exposing `GET /weight` over localhost, and a `Scale` driver interface with two drivers —
   `embedded_barcode` and `agent`. The agent is a separate deliverable with its own release; the till
   degrades to typing the weight when it is absent, and says so rather than silently freezing.
   **The certified indicator on the scale remains the legal display of weight**; this reads it, it does
   not replace it (see Not doing).

Products gain `is_weighed`, a selling `unit` (kg / g / litre), a `price_per_unit` and an optional tare.
A weighed line's quantity is a decimal, which the schema already allows — `stock_movements.quantity` and
`invoice_lines.quantity` are decimals, so nothing structural is needed for the stock or the ledger.

**Receipt printers and cash drawers** are ESC-POS over the browser's print path or through the same
agent; the drawer opens on a print. Neither is an integration to write so much as a template and a
setting per store — but the setting has to exist per *till*, not per store, because two counters in one
shop have two printers — hence `store_devices` in §12 and Phase 1.

**Prep displays (KDS).** A full-screen Livewire page per station showing the queue: fired, preparing,
ready. Push-based, and the infrastructure is already here — Reverb is implemented and every panel page
already boots Echo (`docs/realtime-notifications-plan.md`, Status: Implemented), so this is a new private
channel per store and station plus an authorization line in `routes/channels.php`, not a new transport.
**With a polling fallback, deliberately**: Reverb runs on a separate VPS because production hosting
cannot run persistent processes, so a shop whose link to that box is down must still see its orders. A
kitchen display that silently stops updating is worse than one that refreshes every ten seconds.

**Table plans.** `store_zones` and `store_tables` (zone, code, seats, position on a plan), an order held
open against a table, and the operations that make it useful: transfer to another table, merge two, split
a bill by line, and print a proforma before payment. The floor plan is a grid of positioned tables with
each table's state — free, open with a total, ready to pay. This is the smallest part of §8 in schema
terms and the largest in interaction terms, which is the opposite of how it looks.

## §9 Orders: the piece both of those need

Neither a prep display nor a table plan is possible while a sale is only ever a completed invoice. You
fire a croissant before anybody has paid; a table's tab is open for an hour. So the till gains a document
in front of the invoice, and this is the one place this plan revises §5's "a sale is an `Invoice`":

**`pos_orders` → an `Invoice` at payment.** An order (store, till, opened_by, table, customer, status:
`open` / `fired` / `ready` / `paid` / `abandoned`) with `pos_order_lines` (product, variant, quantity,
unit price, discount, prep station, prep state, note). Payment converts it: the invoice is created,
posted, tendered and reported exactly as in §5, with the order retained as its origin.

Three consequences worth stating before they are discovered:

1. **An order reserves stock; it does not consume it.** The COGS and the stock movement happen at
   payment, as they do today. A fired order that is abandoned is *waste* if it was made and nothing if it
   was not — which is a decision a bakery makes daily, so the abandon action asks and records the answer.
2. **Prices are fixed when the line is added**, not when the bill is paid. This is the same rule
   `InvoiceService` already applies to exchange rates — "the rate is inherited, never re-fetched", because
   re-deriving it would credit today's rate against yesterday's posting. A tab opened before a happy-hour
   promotion ended keeps the price it was quoted.
3. **An order is not a fiscal document.** Nothing goes to FBR until the invoice exists. The proforma
   print must be visibly not a receipt, or a customer walks out with a document that looks like a tax
   invoice and is not.

Straight-serve retail — the cloth shop, the shoe shop, the supermarket — never opens an order: the till
takes a line straight to a sale as in §5. The order path is what the `tabs & tables` and `prep display`
capabilities switch on.

## §10 Loyalty and promotions

Both change what a line costs, so both have to be right in the ledger, not just on the receipt.

**Promotions: a rule table, evaluated deterministically.**

`promotions` (name, type, scope, priority, exclusive, valid_from/to, time-of-day window, store and
channel scope, customer-group scope, active) with `promotion_rules` for the parameters. Types worth
having, and no more: percentage off, amount off, buy X get Y, bundle price, threshold discount
(spend over N), and a time window (a bakery's end-of-day markdown, which is the type that pays for the
feature).

The decisions that make it usable rather than a source of arguments:

- **A discount is a posting, not a smaller number.** Every discounted line carries the promotion that
  discounted it and the amount, and the discount posts to a **contra-revenue** account rather than
  quietly reducing revenue. Otherwise gross margin by product is a lie and the markdown report has
  nothing to read. This is the single most important sentence in §10.
- **Priority and exclusivity are explicit.** Rules are applied in priority order; an exclusive rule stops
  the rest. Stacking that "just works" is stacking nobody can predict, and the till must be able to say
  *why* a price is what it is — a line's applied-promotions list is shown at the counter and kept on the
  invoice.
- **Manual overrides stay separate.** A cashier discount is not a promotion: it needs the
  `PriceOverride` permission, a reason, and its own exception report (§7). Conflating the two loses the
  control that report exists for.

**Loyalty: points are a liability.**

`loyalty_programs` (earn rate, redemption value, expiry rule, per-store or company-wide),
`loyalty_accounts` (contact, card number, tier, balance) and `loyalty_transactions` (earn, redeem,
adjust, expire, with the invoice that caused it). Then the part most implementations get wrong:

- **Earning points is an accrued cost, and redeeming them relieves it.** Points outstanding are money
  the company owes in goods, so earning posts a provision and redemption releases it — which makes
  *Points Liability* a report that reconciles to an account, like everything in the retail reporting set.
  Getting this wrong understates a liability that grows quietly for years.
- **Expiry is a posting too**, and it is the one that will be forgotten: unredeemed points that lapse
  release the provision to income, on a schedule, per program.
- **Identifying the customer** is the same mechanism a warehouse club's membership uses (§1) — a contact
  at the till, by card number, phone or scan. One implementation, two features.

## §11 The online storefront

A public shop, selling the same stock through the same ledger. This is the largest single addition in
this plan and the one furthest from the rest of the application, so its boundaries matter more than its
features.

- **It is an `Invoice` too, through an order.** A web order is a `pos_orders` row with `channel = online`
  and a fulfilment state (§9), so payment, stock, COGS, tax and FBR reporting are the same code path the
  till uses. A separate `web_orders` table with its own posting would be a second ledger integration to
  keep in step, and it would drift.
- **Public routes, not the panel.** `docs/company-website-plan.md` is the precedent and carries the
  warning worth reading first: its admin half was specified in Nova and is stale, but the public half —
  Livewire full-page components, Blade, Tailwind through Vite, public routes beside the panel — is
  exactly this shape and is unaffected. That doc also asks the right first question, which applies here
  too: whether a public storefront belongs in this codebase at all, or in a separate application against
  an API. **Answer it in Phase 0, not in Phase 10.**
- **Stock reservation is the hard part, not the catalogue.** A cart that holds stock blocks the shop
  floor; a cart that does not oversells online. The design: reserve on checkout for a bounded window,
  release on expiry, and report reservations as a distinct line in the stock-on-hand report so a shop
  assistant can see why the count disagrees with the shelf.
- **One payment gateway, named** (see Not doing), with the webhook idempotent on the provider's event id
  — the same discipline as `fbr_submissions`' idempotency key and the delivery key in
  `docs/reports-expansion-plan.md` Phase 8. A double-posted payment is a customer service incident with a ledger consequence.
- **Guest checkout creates a contact**, which is where the walk-in decision of §5 pays off: if
  `contact_id` is nullable and a cash sale posts to cash, a guest order needs no placeholder customer.
- **Fulfilment**: pick, pack, dispatch, deliver, return — with the return being the credit note and the
  stock movement §2.1 already has to add. Returns are more common online than in a shop by an order of
  magnitude, so this is not an edge case.

## §12 Offline-first, from the first release

Taken into the first release deliberately: a till that stops selling when the internet does is not a
till, and in Pakistan a shop with a reliable connection is an assumption rather than a fact. It is also
the single largest source of complexity in this plan, and every item below is load-bearing.

**What the till holds locally.** A PWA — service worker for the app shell, IndexedDB for the data — with
a *snapshot*: products, barcodes, prices, tax rates, active promotions, the store's own settings, and a
`snapshot_version`. The till records which version priced each sale, so a sale priced against a stale
list is *identifiable* afterwards rather than silently wrong. That is the same principle
`InvoiceService` already applies to exchange rates: the price a document was made with is a fact about
the document, not something to re-derive later.

**Numbering, solved by reservation rather than by renaming.** `store_sequences` hands each till a
*block* of numbers (till A: 1001–2000). An offline sale takes its final number from the block, so
nothing is renumbered on sync and no receipt in a customer's hand ever becomes wrong. A till near the end
of its block requests another; a till that exhausts one offline stops issuing rather than inventing.

**Replay is idempotent or it is nothing.** Every sale carries a client-generated UUID and the replay
endpoint is unique on it, so a retried upload posts once. This is the same lesson as the payslip
duplicate-email fix and the delivery key in the reports plan — a retry is normal, and correctness cannot
depend on it not happening.

**Stock, honestly.** The till tracks a local estimate: snapshot on-hand minus its own sales. Two tills
offline in the same shop can both sell the last unit, and no amount of software prevents that — the
answer is that the shortfall arrives as a **variance to be resolved by a count**, recorded with the sales
that caused it, never silently absorbed. This is why §2.1's negative-stock policy has to be decided
before the till is written and not after.

**Payments.** Card terminals that need an authorisation are offline too. So an offline till takes cash
(and any tender the terminal can store-and-forward), and refuses the rest with a sentence saying why.
Anything else is a promise the hardware cannot keep.

**Signing in with no network.** A cashier must be able to open the till on a dark connection, which means
a **registered device** (`store_devices`: store, name, token, last_seen, revoked_at) plus a locally cached
credential for the staff allowed on it. That is a real security surface — a device in a shop holding the
catalogue and a credential — so: device registration is an administrator action, revocation is immediate
and checked on every sync, the cached credential is a hash with a short offline validity, and a device
that has not synced for N days stops selling. Say the number in Phase 0 and test it.

**The compliance crunch, stated plainly.** Real-time fiscal reporting and offline selling are in tension
by definition: an offline sale cannot be reported in real time. What happens to a receipt issued in that
window — whether it may carry a locally generated number and be submitted on reconnect, whether an
offline-mode registration exists, what the penalty position is — is a **regulatory** question, not a
technical one. §6's caveat applies with more force here, and this specific question goes at the top of the
list for the advisor. **Until it is answered, offline mode must be a per-store setting that is off by
default**, so a company subject to real-time reporting cannot switch it on by accident.

**Testing it is possible in this repository.** `puppeteer` is already a dependency and is already used to
drive the panel for visual checks. An offline test boots the till, sets the browser offline, sells,
reconnects and asserts exactly one invoice with the number the block promised. Without a test at that
level, offline is a claim rather than a feature.

## Phases

Each phase is shippable and each leaves the application working. The order is not negotiable in two
places: §2 before the till, and §9's order document before anything in §8 that depends on it.

- **Phase 0 — Decide, then measure.** The decisions that cannot be reversed cheaply, all of which now
  have to be made before code: store as a dimension (§0.1); `store_id` on journal lines vs a chart per
  store (§2.2); one `retail` module vs `retail` + `pos` (§4); the negative-stock policy (§2.1, and §12
  makes it urgent); whether the storefront lives in this codebase or a separate application against an
  API (§11); the offline device-staleness limit in days (§12). Write the answers into this document.
  Then a query-budget test for the till screen before it exists — a POS that takes 40 queries to add a
  line is unusable, and the ceiling is easier to defend from the start.
- **Phase 1 — Stores exist.** `stores`, `store_devices`, `store_user`, `StoreAccess`, `StoreScope`, the
  switcher, permissions, the module wiring of §4. No selling yet. Ends with: an administrator can create
  two stores, register a till device, assign staff, and every existing screen still works.
- **Phase 2 — Stock becomes per-store.** `stock_movements.store_id` with a backfill to a default store,
  the store-aware valuation API, transfers, waste, count adjustments, and the negative-stock guard
  chosen in Phase 0. **The migration is the risk** — see below. Ends with: on-hand per store is right and
  the company total is unchanged, which is the assertion.
- **Phase 3 — The till, offline-capable from the start (§5 + §12).** Barcodes, the POS page, tenders,
  till sessions, receipts, per-store numbering *by reserved block*, refunds — and with them the PWA
  shell, the IndexedDB snapshot with its version, the idempotent replay, offline sign-in on a registered
  device, and the puppeteer test that sells with the network off. This is a large phase and splitting it
  is a false economy: retro-fitting offline means revisiting numbering, pricing, stock and auth, which is
  every decision the till makes. **Offline is a per-store setting, off by default, until §6's question is
  answered.**
- **Phase 4 — Reporting and the dashboard.** §7. Every report reconciles to the ledger; the all-store
  comparison lands here, and so do the reports the later phases need to be trustworthy — markdown,
  waste, till variance.
- **Phase 5 — The ledger dimension.** `journal_entry_lines.store_id`, passed by the POS, invoice,
  inventory and payment postings; store profit and loss; the unallocated column. *Can* precede Phase 4
  and probably should if a per-store P&L is what the buyer asked for first.
- **Phase 6 — Type capabilities: variants and batch/expiry.** A size × colour matrix with one stock row
  per variant; batch and expiry with FEFO picking and an expiring-stock report. Both touch every stock
  path, which is why neither is in Phase 2.
- **Phase 7 — Weighed items and peripherals (§8).** Scale-printed barcode parsing first — it needs no
  hardware and covers most weighed retail — then the local agent and its `Scale` driver, receipt
  printers and cash drawers per device. Independent of Phase 8, and much the smaller half of §8.
- **Phase 8 — Orders, tabs, tables and prep displays (§9 + §8).** The order document, then the table
  plan and the station displays on Reverb with a polling fallback. **§9 first, always**: the display and
  the floor plan are views of an order, and building either without it produces a second sale model.
- **Phase 9 — Promotions and loyalty (§10).** The rule table, contra-revenue posting for discounts, the
  applied-promotions list on the invoice and the markdown report; then loyalty with its provision, its
  redemption relief and its expiry posting. Promotions before loyalty: loyalty is a promotion that pays
  in points, and it needs the same evaluation order.
- **Phase 10 — The online storefront (§11).** Public routes, catalogue, cart, checkout, one named
  gateway with an idempotent webhook, stock reservation with a bounded hold, and fulfilment with
  returns. Gated on Phase 0's answer about where it lives.
- **Phase 11 — FBR POS (§6).** Only after the advisor's questions are answered — including §12's, which
  is now the first of them — and reusing the existing driver layer rather than adding a second one.

## Risks

- **The stock backfill is a one-way door.** Every existing movement must land in a store, and the
  choice of default store is a judgement about history that cannot be re-made later. Mitigation: the
  migration is reversible, it is tested against a copy of a real tenant, and the assertion is that
  every product's company-wide on-hand and value are **identical before and after** — that is the only
  proof the backfill did not move value.
- **Negative stock will happen.** Two tills selling the last item, a transfer received twice, an
  offline replay. A hard constraint at the database level stops the sale — which in a shop means
  refusing a customer who is holding the goods. Decide the policy deliberately (allow with a flag and
  a report, or block), write it in this document, and test the chosen one. Silence here means both
  behaviours appear in different code paths.
- **A per-row cost in the till.** Every line asks for a price, a tax rate and an on-hand figure. Done
  naively that is three queries a line and a stocktake screen with 400 rows is 1,200. The lesson from
  `/payroll-runs` and `/tax-rates` applies directly: aggregate in the query, and pin it with a test
  that renders **with rows** — the resources smoke test creates only a user, so every table it renders
  is empty and it waved two 500s through.
- **Scope creep from ten store types.** The table in §1 is the defence: three capabilities, not ten
  workflows. Anything that cannot be expressed as a capability flag is a new plan, not a new branch in
  the POS component.
- **Compliance exposure is the real risk, not technical debt.** Every other risk here is a bug. Issuing
  non-compliant retail invoices, or amending a reported one after 72 hours, is a legal exposure for the
  company using the software — which is why Phase 11 is last and gated on advice rather than on
  engineering, and why offline mode ships switched off.
- **A store switcher that reads as a permission.** If selecting a store persists somewhere invisible,
  an administrator will one day conclude the data is gone. "All stores" is the default, the selection
  lives in the URL, and the pane says which store it is showing.
- **Offline selling against real-time fiscal reporting.** The one risk here that is not an engineering
  risk. The two requirements are in direct tension and the resolution is regulatory; building offline
  first and asking afterwards would put non-compliant receipts in customers' hands. Mitigation: the
  setting is off by default, the question is first on the advisor's list (§6, §12), and the delivery of
  Phase 3 does not depend on the answer — only switching it on does.
- **A till device is a credential and a catalogue sitting in a shop.** Offline sign-in means something
  cached on hardware that leaves with whoever takes it. Mitigation is in §12 and is specific: registration
  by an administrator, immediate revocation checked on every sync, a hashed credential with a short
  offline validity, and a device that has not synced for N days refusing to sell. The number belongs in
  Phase 0 and the refusal belongs in a test.
- **Offline oversell arriving as a mystery.** Two tills, one last unit. The shortfall is physical and the
  software's only honest job is to attribute it: a variance carrying the sales that caused it, resolved by
  a count. If it is absorbed silently, every stock figure in the shop loses its meaning and nobody knows
  when it happened.
- **A discount that reduces revenue instead of posting to contra-revenue.** The quiet failure of §10: the
  receipt is right, the customer is happy, and gross margin by product is wrong forever with nothing to
  reconcile against. It is one line of posting code and it is the reason the markdown report can exist at
  all. Any promotion path that does not carry its promotion id and amount onto the invoice line should
  fail review.
- **Points as a growing unrecorded liability.** A loyalty scheme with no provision understates what the
  company owes in goods, for years, invisibly — and it is the kind of thing found at an audit rather
  than by a user. The provision, the relief on redemption and the release on expiry are three postings
  and all three are in Phase 9 for that reason.
- **The storefront as a second application in disguise.** Public routes, a theme, SEO, a gateway, a cart
  — none of it shares a screen with the panel, and `docs/company-website-plan.md` already records what
  happens to a public-facing plan that ages inside a private-panel codebase: its admin half was written
  against Nova and had to be discarded. Deciding in Phase 0 whether the storefront lives here or against
  an API is what stops that repeating.
- **A prep display that stops updating and looks fine.** Reverb runs on a separate VPS because production
  hosting cannot run persistent processes, so a shop's kitchen screen depends on a box outside the shop.
  A silent stale display is worse than a slow one: the polling fallback in §8 is not a nicety, and the
  screen should say when it last heard from the server.

## Suggested first slice

**Phases 0–3.** Stores, access, the switcher, stock that is true per outlet, and the till — offline from
the first release, because retro-fitting it means revisiting numbering, pricing, stock and authentication
all at once.

Phases 0–2 alone are still worth shipping on their own if the till has to wait: they answer the question
the request opens with, since a company with three shops currently cannot be told what is on the shelf in
one of them.

Everything from Phase 6 onward is genuinely optional per customer, and the capability flags in §1 are what
make that true: variants for the cloth and shoe shops, weighed items for the bakery and supermarket,
orders and tables for the café side, promotions and loyalty for the discount store and the club,
fulfilment for the online shop. **No company gets all of them**, and the plan is built so that none of
them is in the way of the others.

One honest note on size: with §8–§12 in scope this is no longer a feature — it is a retail product inside
an accounting application, and Phases 3, 8, 10 and 12 are each the size of a plan in this folder. The
phase boundaries are drawn so that stopping after any of them leaves something coherent to sell.
