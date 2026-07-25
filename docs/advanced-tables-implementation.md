# Advanced Tables (Saved Views & Table UX) — Implementation Plan

**Status:** Proposed
**Created:** 2026-07-25
**Inspiration:** [Kenneth Sese — Advanced Tables for Filament](https://filamentphp.com/plugins/kenneth-sese-advanced-tables#features)

Goal: let users **save, favorite, share, and default** table configurations (filters + toggled columns + column order + sort + search) per resource — plus developer-defined **preset views** — all **scoped to the current company** and gated by our per-company policies.

This is a large plugin (60+ options). This doc scopes a **pragmatic in-house v1** of the highest-value features, mapped onto our stack, and flags the **buy option** (the plugin is mature, tenant-aware, and cheaper than reimplementing everything — see [Alternatives](#alternatives)).

---

## 1. Feature map — plugin → this codebase

| Plugin feature | Build in v1? | Notes for our stack |
|----------------|:---:|---------------------|
| **User Views** (save filters/columns/sort/search; favorite) | ✅ | Store per user **+ company** (spatie teams). Core of v1. |
| **Preset Views** (developer-defined, query mods, badges, icons) | ✅ | Static `presetViews()` per List page; permission-gated. |
| **Managed Default View** (user picks their default per table) | ✅ | `is_default` flag per (user, company, resource); auto-apply on mount. |
| **Favorites Bar** (toolbar of favorite views + quick save) | ✅ | Livewire widget in the table header; one theme in v1 (not six). |
| **Quick Save** (one-click save current state) | ✅ | Modal: name + icon + color. |
| **View Manager** (search/apply/edit/delete/favorite) | ◑ | Simple dropdown/panel in v1; full side-panel later. |
| **Column & layout** (toggled cols, order) persisted in views | ✅ | Capture Filament table state (toggled columns, order, sort). |
| **Public / shared views** + approval workflow | ◑ | `is_public` + policy in v1; **approval workflow deferred**. |
| **Global favorites** (admin-set for everyone) | ◑ | `is_global` set by Administrators; v1 optional. |
| **Advanced Filter Builder** (OR/AND groups) | ✕ (defer/buy) | Big subsystem; Filament's stock filters cover most needs. |
| **Advanced Search** (constraints: starts-with, matches, …) | ✕ (defer/buy) | Defer; stock search + our command palette cover discovery. |
| **Multi-Sort** (sort by multiple columns) | ◑ | Filament v3.2+/v5 supports multi-sort natively; expose + persist. |
| **Quick Filters** (pinned clickable indicators) | ◑ | Nice-to-have; later. |
| **Loading skeleton** | ✅ | Cheap; add a table loading overlay. |
| **Multi-tenancy / Policies / Dark mode / i18n** | ✅ | We already have all four — must be honored (see §4). |

Legend: ✅ v1 · ◑ partial/later · ✕ defer or buy.

---

## 2. Architecture overview

```
ListRecords page  ── uses ──▶  HasSavedViews (trait)
    │  capture(): serialize current table state
    │  apply(array $state): restore filters/columns/sort/search
    ▼
SavedViewsBar (Livewire)  ── renders favorites + Quick Save + View Manager
    │
    ▼
TableView (model, landlord DB)  ── per user + company + resource
PresetViews (static, per List page)  ── developer-defined
```

- A **trait** on each `ListRecords` page adds view capture/restore + renders the bar in the table header.
- **State** is whatever Filament persists per table: `tableFilters`, `toggledTableColumns`, `tableColumnSearches`, `tableSort` (column + direction, or multi-sort array), `tableSearch`, `tableGrouping`.
- **Persistence** in a `table_views` table (landlord DB, tagged with `company_id`).

---

## 3. Data model

`table_views` — **landlord** connection (metadata about users, who live in the landlord DB; tagged with company for tenant scoping, mirroring `activity_log`):

```php
Schema::create('table_views', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('company_id')->index();   // tenant scope (spatie team)
    $table->unsignedBigInteger('user_id')->index();      // owner (nullable for global/preset-persisted)
    $table->string('resource');                          // Resource or page class / key
    $table->string('name');
    $table->string('icon')->nullable();                  // heroicon name
    $table->string('color')->nullable();                 // filament color name
    $table->boolean('is_favorite')->default(false);
    $table->boolean('is_public')->default(false);        // shared with the company
    $table->boolean('is_global')->default(false);        // admin-pinned for everyone in company
    $table->boolean('is_default')->default(false);       // this user's default for this table
    $table->json('state');                               // {filters, toggledColumns, columnOrder, sort, search, grouping}
    $table->unsignedInteger('sort')->default(0);         // ordering in the bar
    $table->timestamps();
    $table->index(['company_id', 'user_id', 'resource']);
});
```

**`App\Models\TableView`** — landlord-pinned model (see [[cross-db-join-pitfall]] — pin the connection so it doesn't inherit the tenant connection):

```php
class TableView extends Model
{
    protected $fillable = [...];
    protected $casts = ['state' => 'array', 'is_favorite' => 'boolean', /* … */];

    public function getConnectionName(): ?string
    {
        return config('multitenancy.landlord_database_connection_name') ?: config('database.default');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->company_id ??= \App\Models\Company::current()?->getKey());

        static::addGlobalScope('tenant', function ($q) {
            if ($id = \App\Models\Company::current()?->getKey()) {
                $q->where('company_id', $id);
            }
        });
    }
}
```

This reuses the exact tenant-scoping pattern from `App\Models\ActivityLog` — see [[permission-teams-model]].

---

## 4. Tenant & permission integration (the important part)

- **Scope everything to the current company.** Views are `(company_id, user_id, resource)`. The global scope + `creating` hook (above) key off `Company::current()`, which is set by `SyncSpatieTenant` on tenant switch. A user's saved views in Company A never appear in Company B.
- **Ownership & sharing via a policy.** `TableViewPolicy`: a user can edit/delete their own views; `is_public` views are viewable by company members; only **Administrators** (per-company role — [[permission-teams-model]]) may set `is_global`/approve public views. Filament honors the policy automatically.
- **Preset views** may modify the query via Eloquent — ensure those closures run on the **tenant connection** (they will, since the resource's model is a tenant model). Don't join landlord tables (see [[cross-db-join-pitfall]]).
- **Default view** is per user *and* company (a user can default to different views in different companies).

---

## 5. Capturing & restoring table state (Filament v5)

A `HasSavedViews` trait for `ListRecords` pages:

```php
trait HasSavedViews
{
    public function captureViewState(): array
    {
        return [
            'filters'        => $this->tableFilters ?? [],
            'toggledColumns' => $this->toggledTableColumns ?? [],
            'columnSearches' => $this->tableColumnSearches ?? [],
            'search'         => $this->tableSearch ?? null,
            'sortColumn'     => $this->tableSortColumn ?? null,
            'sortDirection'  => $this->tableSortDirection ?? null,
            'grouping'       => $this->tableGrouping ?? null,
        ];
    }

    public function applyViewState(array $s): void
    {
        $this->tableFilters        = $s['filters'] ?? [];
        $this->toggledTableColumns = $s['toggledColumns'] ?? [];
        $this->tableColumnSearches = $s['columnSearches'] ?? [];
        $this->tableSearch         = $s['search'] ?? '';
        $this->tableSortColumn     = $s['sortColumn'] ?? null;
        $this->tableSortDirection  = $s['sortDirection'] ?? null;
        $this->tableGrouping       = $s['grouping'] ?? null;
        $this->resetPage();
    }

    // mount(): if a default view exists for (user, company, resource), applyViewState()
}
```

> The exact property names above must be verified against the installed Filament version during Phase 1 (they've shifted across v3→v5). Toggled-column *order* may need `reorderableColumns()` enabled and reading the order state.

Rendering the bar: a `SavedViewsBar` Livewire component embedded via the page's `getTableHeader()`/a header action, or a `renderHook`. It lists the user's favorites + public/global views for the resource, a **Quick Save** button (name/icon/color modal), and a **View Manager** dropdown (apply/edit/delete/favorite/set-default).

---

## 6. Phased build plan

- [x] **Phase 1 — Foundation.** `table_views` migration (landlord, applied) + `App\Models\TableView` (connection-pinned, `company_id` global scope, casts, `visibleTo` scope) + `TableViewPolicy` (owner/public/global + `setGlobal` for Administrators) + `TableViewFactory`. `App\Filament\Concerns\HasSavedViews` trait captures/restores the verified v5 state (`tableFilters`, `tableColumns`, `tableColumnSearches`, `tableSearch`, `tableSort`, `tableGrouping`).
- [x] **Phase 2 — Save & apply user views.** `saveView` header action (modal: name/icon/color/favorite/default/share) persists the captured state; a **Views** `ActionGroup` dropdown lists saved views and applies state on click. Wired on `Payslips`, `Invoices`, `Products` via the trait.
- [x] **Phase 3 — Default view.** Per-user, per-company `is_default` auto-applied on `mountHasSavedViews()`; favorites sorted first in the dropdown. (A dedicated always-visible favorites *bar* is deferred to polish; the dropdown covers the function.)
- [x] **Phase 4 — Preset views.** `presetViews(): array` per List page (name/icon/color/state), merged ahead of user views. Demoed on `Products` ("Name A→Z", "Newest first").
- [x] **Phase 5 — Sharing & admin.** `is_public` (share with company) + `is_global` (Administrator-only via policy) + `TableViewResource` (Access Control group, `canAccess` = Administrator) to manage/edit/delete all views in the company.
- [~] **Phase 6 — Polish / stretch.** Loading skeleton, quick filters, dedicated favorites bar, view reordering, i18n, multi-sort UI remain as stretch. (Advanced Filter Builder & Advanced Search stay **out of scope** — defer or buy.)

Verified by `TableViewTest` (per-company scoping, policy, Livewire save-captures-state). Rolled out per resource via the trait — no global switch. Suite: 150 passed.

---

## 7. Testing

- **Model/scope:** a view saved in Company A is invisible in Company B (reuse `InteractsWithTenant` + two companies; mirror `TenantActivityLogTest`).
- **Policy:** non-owners can't edit others' private views; only Administrators set `is_global`.
- **Trait round-trip:** `applyViewState(captureViewState())` is idempotent; a saved view restores filters/columns/sort on a Livewire `ListRecords` test.
- **Default view:** mounting the page auto-applies the user's default for the current company.
- Keep the suite green (currently 147) and add `TableViewTest` + a Livewire page test.

---

## 8. Alternatives

- **Buy the plugin** ([Advanced Tables](https://filamentphp.com/plugins/kenneth-sese-advanced-tables#features)) — it already ships User/Preset/Default views, favorites bar, view manager, advanced filter builder, advanced search, multi-sort, global favorites, **multi-tenancy support**, and policies, with 60+ options. **Fastest path**, and it explicitly supports Filament tenancy. **Verify before buying:** our tenancy is *database-per-tenant with the `Company`/membership in the landlord DB* — confirm the plugin's view storage can be pointed at (or works on) the landlord connection and scopes by our spatie **team id**, not a row-level tenant column on a tenant-DB table. That's the one real compatibility risk.
- **Filament built-ins** — session-persisted filters/columns/search (`persistFiltersInSession()`, `persistColumnSearchesInSession()`, `persistSortInSession()`) already give *per-session* memory for free. If "named, shareable, defaultable views" aren't required, enabling these on our List pages is a zero-cost 80% solution.

## 9. Risks / notes

- **Filament state API drift** — the table-state property names must be confirmed against the installed v5 build (Phase 1 gate).
- **Cross-DB** — `TableView` lives in the landlord DB and must be connection-pinned; never join it to tenant tables ([[cross-db-join-pitfall]]).
- **Serialized state validity** — a saved filter/column may reference something later removed from a resource; `applyViewState()` must ignore unknown keys defensively.
- **Scope creep** — the Advanced Filter Builder and Advanced Search are each large; keep them out of the in-house v1 (buy if truly needed).
- **Per-company defaults** — remember a user has one default *per company*, not one global default.
