# Custom Fields — Implementation Plan

**Status:** Proposed
**Created:** 2026-07-25
**Source:** [Relaticle Custom Fields — plugin page](https://filamentphp.com/plugins/relaticle-custom-fields#key-features) · [install docs](https://relaticle.github.io/custom-fields/getting-started/installation)

Goal: let each company define its **own custom fields** (no code/migrations) on domain records — e.g. extra attributes on Employees, Invoices, Products, Contacts — managed from the Filament admin, and shown in forms/tables/infolists.

Relaticle Custom Fields is a **paid** plugin ($79, private Satis repo). It's a strong fit *functionally*, but its built-in multi-tenancy assumes **row-level** tenancy (a `tenant_id` column), whereas we run **database-per-tenant**. The main work is bending it onto our model. This doc covers both **buying/integrating it** and a **build-it-ourselves lite** fallback.

---

## 1. What the plugin provides

- **20+ field types** — text, number, date, select/multiselect, rich editor, file upload, toggle, etc.
- **No-migration schema** — fields are defined **in the UI**; values stored in an EAV-style value table.
- **Deep Filament integration** — one component drops custom fields into **forms, tables, infolists**.
- **Type-safe validation**, **conditional visibility** (show/hide based on other fields), **optional per-field encryption**.
- **Multi-tenancy** — "complete tenant isolation and context management" (row-level model).
- **CSV import/export** of custom-field data.
- **Admin UI** to manage field definitions.

**Requirements** (all satisfied here): PHP 8.3+ (we're 8.4), Laravel 12+ (we're 13), Filament 5.x (5.7), Tailwind 4 (Filament v5 theme). ✅

---

## 2. The core challenge: their tenancy vs ours

| | Relaticle assumes | This app |
|---|---|---|
| Isolation | Row-level `tenant_id` on shared tables | **Database-per-tenant** (`Company` + membership in landlord; domain data per-tenant DB) |
| Tenant model | Filament row-scoped tenant | `App\Models\Company` (spatie multitenancy) + `Resource::scopeToTenant(false)` globally |

**Decision:** put the plugin's tables (`custom_fields`, `custom_field_values`, …) in the **tenant** database and let **DB isolation** provide tenancy — *disable* the plugin's row-level tenant scoping. Then each company's custom-field definitions and values live in its own DB, naturally isolated, exactly like the rest of our domain data. See [[permission-teams-model]] and the DB-split notes in [[filament-laravel13-migration-plan]].

This means:
- The plugin's models must resolve on the **`tenant`** connection (like `App\Models\TenantModel`). If any hardcode the default connection, override it (watch the [[cross-db-join-pitfall]] connection-inheritance trap).
- The plugin's migrations must live under `database/migrations/tenant/` so new companies get them via the provisioner, and existing companies via `tenants:artisan`.
- Turn off the plugin's own tenant-column scoping (config flag or by not configuring a Filament tenant column for it).

---

## 3. Install & setup (adapted to our repo)

> Requires a purchased license (private Satis repo). Confirm licensing before starting.

1. **Composer** (private repo + auth):
   ```bash
   composer config repositories.relaticle composer https://satis.relaticle.com
   composer require relaticle/custom-fields:^3.0
   ```
2. **Installer:** `php artisan custom-fields:install` — then **move/republish its migrations into `database/migrations/tenant/`** (do NOT leave them on the landlord path, or every domain seeder/test on the single DB will diverge from production). Verify no landlord-only assumptions.
3. **Theme CSS** — add to `resources/css/filament/admin/theme.css`:
   ```css
   @source '../../../../vendor/relaticle/custom-fields/resources';
   ```
   then rebuild assets (`npm run build`).
4. **Config:** `php artisan vendor:publish --tag="custom-fields-config"` — set the models' connection to `tenant` and **disable row-level tenancy** (use DB isolation instead).
5. **Register the plugin** on the panel in `AdminPanelProvider` (`->plugin(CustomFieldsPlugin::make())`), configuring it to *not* scope by a Filament tenant column.
6. **Per-tenant migrate:** `php artisan tenants:artisan "migrate --force"` so existing companies (erbium, default) get the custom-field tables. New companies get them automatically via `CompanyProvisioner`.

---

## 4. Opting models in

Custom fields attach to **tenant domain models**. Add the plugin's trait/interface (verify exact names in the installed version — likely `Relaticle\CustomFields\Models\Concerns\UsesCustomFields` + a `HasCustomFields` contract) to the models we want extensible, e.g.:

- **High value:** `Employee`, `Contact`, `Invoice`, `Product`, `Beneficiary`, `FixedAsset`.
- Each already extends `App\Models\TenantModel`, so the custom-field values (tenant DB) and the parent (tenant DB) are on the **same connection** — no cross-DB relation issues. ✅

Then add the plugin's component to that resource's form / table / infolist (one line each), e.g. `CustomFieldsComponent::make()` in the form schema and the columns/entries helpers in the table/infolist.

---

## 4b. IMPLEMENTED — in-house native custom fields ✅

The paid Relaticle plugin needs a purchased license + private repo, so we built the **§7 "lite EAV" alternative** natively for our DB-per-tenant model instead. Delivered:

- **Tenant tables** (`database/migrations/tenant/..._create_custom_fields_tables.php`): `custom_fields` (definitions: model_type, code, name, type, options, is_required, help, sort, is_active) + `custom_field_values` (morph to any tenant model, json value). Isolation via the tenant DB — no `tenant_id` column. Applied to existing tenants via `tenants:artisan "migrate --path=database/migrations/tenant --database=tenant --force"`; new companies get them from the provisioner.
- **Models:** `App\Models\CustomField` + `App\Models\CustomFieldValue` (both extend `TenantModel`). Trait `App\Models\Concerns\HasCustomFields` (morphMany values, `customFieldsData()`, `saveCustomFields()`).
- **Field types:** text, textarea, number, date, boolean, select — with required + help.
- **Filament integration:** `App\Filament\Support\CustomFieldsSchema::form()` / `::tableColumns()` build components/columns from definitions (form fields under the `custom_fields` state path, `dehydrated(false)`); `App\Filament\Concerns\InteractsWithCustomFields` page trait hydrates + persists values on Create/Edit.
- **Admin:** `CustomFieldResource` (Settings nav group, Administrator-only) to manage definitions per model type (`MODELS` allow-list).
- **Rolled out to 6 models:** `Contact`, `Employee`, `Invoice`, `Product`, `Beneficiary`, `FixedAsset` (model trait + form + table columns + Create/Edit page traits + `getEloquentQuery` eager-load).
- **Infolist entries:** `CustomFieldsSchema::infolistEntries(Model::class)` for View pages.
- **Per-field validation:** `min`/`max` (numeric value or text length) + `regex` per definition, applied to the form component and enforced on save.
- **N+1 fixed:** `customFieldsData()` memoized per instance + reuses eager-loaded `customFieldValues.customField`; definitions cached per (model, company) in a real tenant context. Each opted-in resource eager-loads via `getEloquentQuery()->with('customFieldValues.customField')`.
- **Tests:** `CustomFieldTest` (define+store, Filament create persists, min-length validation enforced, infolist entries build). Suite: 154 passed.

**Still deferred (lower priority / buy):** field encryption, CSV import/export, conditional visibility (show/hide by another field), the long tail of field types (multi-select, file, rich editor, color), table filtering on custom columns, PDF/report inclusion.

**To opt another model in:** add `use HasCustomFields;` to the model, `...CustomFieldsSchema::form(Model::class)` to its form + `...::tableColumns(Model::class)` to its table, `use InteractsWithCustomFields;` on its Create/Edit pages, and add it to `CustomFieldResource::MODELS`.

## 5. Phased plan (original — for the paid plugin route)

- [ ] **Phase 0 — Licensing & spike.** Acquire license; install in a branch; confirm Tailwind-4 theme builds; read the plugin's model/connection/tenancy internals to confirm we can (a) point its tables at the `tenant` connection and (b) disable row-level tenancy.
- [ ] **Phase 1 — Tenant wiring.** Relocate migrations to `database/migrations/tenant/`; run via `tenants:artisan`; ensure the plugin's models use the tenant connection (subclass/override if needed); disable its tenant-column scoping. Prove isolation: a field defined in Company A is absent in Company B.
- [ ] **Phase 2 — Pilot one model.** Enable custom fields on **`Contact`** (low-risk, few dependencies). Define a field in the UI, set a value on a record, render it in form + table + infolist. Verify policy/permission gating still applies.
- [ ] **Phase 3 — Roll out.** Add the trait + components to `Employee`, `Invoice`, `Product`, `Beneficiary`, `FixedAsset`. Decide per-resource whether custom fields show in the table (toggle) vs form/infolist only.
- [ ] **Phase 4 — Validation, conditional, encryption.** Configure type-safe validation, conditional visibility, and encryption for any sensitive fields (e.g. on Employee).
- [ ] **Phase 5 — Import/export & admin.** Wire CSV import/export where useful; confirm the field-definition admin UI is gated to Administrators (per-company role).
- [ ] **Phase 6 — Backup/PDF/report awareness.** Ensure custom-field values flow into relevant PDFs/reports and the landlord-cleanup/data-migration tooling is aware of the new tenant tables.

## 6. Testing

- **Tenant isolation:** definitions + values created in Company A are invisible in Company B (reuse `InteractsWithTenant` + two tenant SQLite DBs, like `TenantDatabaseIsolationTest`).
- **Connection:** custom-field value writes hit the tenant DB, never the landlord placeholder.
- **Filament:** a resource form with custom fields renders and saves a value (Livewire `CreateRecord`/`EditRecord` test).
- **Permissions:** only Administrators manage field definitions; field values respect the parent record's policy.
- Keep the suite green (currently 147) and add `CustomFieldsTenantTest`.

## 7. Alternatives

- **Build a lite EAV ourselves** — a `custom_fields` (definition) + `custom_field_values` (morph to any tenant model) pair in `database/migrations/tenant/`, a `HasCustomFields` trait, a Filament field-definition resource, and a small form/table integration. Pros: no license, full control, native to our tenancy. Cons: we'd reimplement 20 field types, conditional logic, encryption, CSV — significant effort. Reasonable only if we need just a handful of simple field types.
- **Buy + integrate (recommended if the tenancy spike passes)** — far less code; the only risk is the tenancy adaptation in §2.

## 8. Risks / notes

- **Licensing** — paid, private repo; CI/deploy needs the Composer auth token.
- **Tenancy adaptation is the whole ballgame** — if the plugin can't be pointed at the `tenant` connection with row-level scoping disabled, integration cost rises sharply. Do the Phase 0 spike before committing.
- **Migrations location** — they MUST go under `database/migrations/tenant/` (a plugin that publishes to the default path would, in our single-DB test env, silently diverge from production and pollute the landlord DB).
- **Connection inheritance** — any custom-field model reached via a tenant model's relation must resolve on the tenant connection ([[cross-db-join-pitfall]]).
- **Tailwind 4 `@source`** — the theme line + `npm run build` are required or fields render unstyled (same class of issue we hit with the command palette).
- **Existing companies** need a one-time `tenants:artisan "migrate --force"` after install.
