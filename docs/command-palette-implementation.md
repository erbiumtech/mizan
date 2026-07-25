# Command Palette (⌘K Spotlight) — Implementation Plan

**Status:** Proposed
**Created:** 2026-07-25
**Inspiration:** [Filament Spotlight Pro](https://denniskoch.dev/projects/filament-spotlight-pro/docs/) — "Zero clicks. Maximum productivity." A Raycast/macOS-Spotlight-style command palette for Filament panels.

Goal: a keyboard-driven overlay (⌘K / Ctrl+K) that lets a user jump to any resource, page, action, or record, and run quick commands — **without a mouse**, and **respecting this app's per-company permissions and tenant isolation**.

We build this ourselves (inspired by Spotlight Pro) so it fits our multi-tenant, teams-based model. The paid package is an alternative if we'd rather not maintain it — see [Alternatives](#alternatives).

---

## 1. Goals & non-goals

**Goals**
- Global hotkey (⌘K / Ctrl+K) opens a modal search from anywhere in the panel.
- Fuzzy search across four result types: **Resources**, **Pages**, **Records** (global search data), **Commands/Actions**.
- Keyboard-only: arrow keys to move, `Enter` to go, `Esc` to close; grouped results with a highlighted active item.
- **Permission-aware:** only surface what the current user can access *in the current company* (policies + per-company roles — see [[permission-teams-model]]).
- **Tenant-aware:** navigation stays within the current company; a "Switch company" command surfaces the user's other companies.
- Light/dark theme aware; no external CDN assets (self-contained, matching our CSP-free but asset-light setup).

**Non-goals (v1)**
- Server-side fuzzy ranking engines (use simple `str_contains`/Levenshtein-lite scoring first).
- Cross-tenant record search (records are per-tenant DB; searching all companies at once is out of scope).
- Command history/recents persistence (nice-to-have, phase 4).

---

## 2. Architecture overview

```
┌─ Alpine (client) ──────────────────────────────────────────┐
│  ⌘K listener → opens <dialog>; captures query; arrow/enter │
│  debounced $wire.search(query) → renders grouped results   │
└──────────────┬─────────────────────────────────────────────┘
               │ Livewire
┌──────────────▼─────────────────────────────────────────────┐
│  CommandPalette (Livewire component)                        │
│   search(string $q): array<Group>                           │
│    └─ iterates registered Providers, merges + ranks         │
│  goto(string $type, string $id): Redirect                   │
└──────────────┬─────────────────────────────────────────────┘
               │
┌──────────────▼─────────────────────────────────────────────┐
│  Providers (each returns scored PaletteItem[])              │
│   ResourceProvider · PageProvider · RecordProvider ·        │
│   CommandProvider (switch company, settings, logout, …)     │
└─────────────────────────────────────────────────────────────┘
```

- **Rendered globally** via a Filament panel **render hook** (`PanelsRenderHook::BODY_END`), so the palette lives on every panel page.
- **One Livewire component** (`App\Filament\Livewire\CommandPalette`) holds state and calls providers.
- **Providers** are small classes implementing a shared contract; the set is easy to extend.

---

## 3. Result types (providers)

| Provider | Sources | Example items | Authorization |
|----------|---------|---------------|---------------|
| **ResourceProvider** | `Filament::getResources()` | "Payslips", "Journal Entries" → list; "New Invoice" → create | `Resource::canViewAny()` / `canCreate()` (honours policies + team) |
| **PageProvider** | `Filament::getPages()` + report pages | "Trial Balance", "Company Settings" | `Page::canAccess()` |
| **RecordProvider** | Filament global search (`Resource::getGlobalSearchResults()`) | "INV-0007 — Acme", employee names | Global search already policy-scoped; tenant DB = current company only |
| **CommandProvider** | hand-registered commands | "Switch company →", "Log out", "Toggle theme", "Go to dashboard" | per-command `visible` closure |

Each provider returns `PaletteItem[]`:

```php
final class PaletteItem
{
    public function __construct(
        public string $group,      // "Resources", "Pages", "Records", "Commands"
        public string $label,      // "Payslips"
        public ?string $subtitle,  // "Payroll", or the record's model label
        public string $url,        // resolved, tenant-aware URL  (or null for JS commands)
        public ?string $icon,      // heroicon name
        public int $score,         // match score for ranking
        public ?string $command = null, // e.g. 'logout' for client-side handling
    ) {}
}
```

Provider contract:

```php
interface PaletteProvider
{
    /** @return PaletteItem[] */
    public function items(string $query): array;
}
```

---

## 4. Permission & tenant integration (the important part)

This app is multi-tenant with **per-company roles** ([[permission-teams-model]]). The palette must never leak actions or records a user can't reach:

- **Resources/Pages:** call the same static gates Filament uses — `ResourceClass::canViewAny()`, `::canCreate()`, `PageClass::canAccess()`. These already run our policies under the **current team id** (set by `SyncSpatieTenant` on tenant switch), so results auto-scope to the current company's permissions.
- **Records:** reuse `Resource::getGlobalSearchResults($query)` — it runs on the **tenant connection** (current company's DB) and is policy-scoped, so no cross-company leakage. Skip resources whose `getGloballySearchableAttributes()` is empty.
- **Switch-company command:** list `auth()->user()->getTenants($panel)` (their `companies`) except the current one; each item deep-links to `/admin/{otherSlug}`.
- **URLs:** always build with the tenant param, e.g. `ResourceClass::getUrl('index', panel: 'admin', tenant: Filament::getTenant())`.

> ⚠️ Do **not** query landlord tables joined to tenant tables in a provider — see [[cross-db-join-pitfall]]. Record search should go through each resource's own global-search query, which resolves on the correct connection.

---

## 5. Keyboard & UX

- **Open:** `⌘K` (mac) / `Ctrl+K` (win/linux). Also a clickable "Search… ⌘K" button in the topbar (Filament `renderHook` `GLOBAL_SEARCH_BEFORE` or `TOPBAR_END`).
- **Navigate:** `↑`/`↓` move the active item across groups; `Enter` opens; `⌘/Ctrl+Enter` opens in new tab; `Esc` closes.
- **Empty state:** show top commands + a few recent resources.
- **Result rows:** icon · label · subtitle (group), with the active row highlighted; group headers sticky.
- **Accessibility:** native `<dialog>` element, focus trap, `aria-activedescendant`, restores focus on close.
- **Theme:** use Filament's CSS variables (`--primary-*`, surface tokens) so it matches light/dark automatically.

Alpine skeleton (client-side, no external deps):

```html
<div x-data="commandPalette()" @keydown.window.cmd.k.prevent="open()" @keydown.window.ctrl.k.prevent="open()">
  <dialog x-ref="dialog" @keydown.esc="close()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="run()">
    <input x-model="query" @input.debounce.150ms="$wire.search(query).then(r => results = r)" placeholder="Search resources, pages, records…">
    <template x-for="(group, gi) in results">
      <!-- group header + items; track activeIndex across the flattened list -->
    </template>
  </dialog>
</div>
```

---

## 6. File layout

```
app/Filament/
  Livewire/
    CommandPalette.php            # Livewire component: search(), state
  CommandPalette/
    PaletteItem.php               # DTO
    PaletteProvider.php           # interface
    Providers/
      ResourceProvider.php
      PageProvider.php
      RecordProvider.php
      CommandProvider.php
    PaletteRegistry.php           # collects providers, merges + ranks
resources/views/filament/livewire/
  command-palette.blade.php       # dialog markup + Alpine
```

Registration in `AdminPanelProvider::panel()`:

```php
->renderHook(
    \Filament\View\PanelsRenderHook::BODY_END,
    fn (): string => \Livewire\Livewire::mount(\App\Filament\Livewire\CommandPalette::class),
)
->renderHook(
    \Filament\View\PanelsRenderHook::TOPBAR_END,
    fn (): string => view('filament.partials.command-palette-trigger')->render(),
)
```

---

## 7. Ranking (v1, simple)

Per item, score = best of:
- exact label match → 1000
- label `str_starts_with` query → 500
- label `str_contains` query → 200 (minus offset of match position)
- subtitle contains → 80
- fuzzy subsequence match → 50

Sort desc, cap each group to ~5, cap total to ~20. Good enough; swap for a real fuzzy lib later if needed.

---

## 8. Phased build plan

- [x] **Phase 1 — Skeleton & hotkey.** `App\Filament\Livewire\CommandPalette` + `resources/views/filament/livewire/command-palette.blade.php` (native `<dialog>` + inline Alpine: ⌘K/Ctrl+K open, ↑/↓ navigate, ↵ open (⌘↵ new tab), esc close, click-outside close, debounced `$wire.search()`). Static "Dashboard" command proves the pipe end to end. Render hook wired via `PanelsRenderHook::BODY_END` in `AdminPanelProvider`. Covered by `CommandPaletteTest` (renders + query filtering). Suite: 144 passed.
- [x] **Phase 2 — Resource & Page providers.** `PaletteProvider` interface + `ScoresMatches` trait (exact/prefix/substring/subsequence scoring, label > subtitle). `ResourceProvider` (list + "New …" entries, gated by `canViewAny`/`canCreate`, tenant-aware `getUrl()`), `PageProvider` (Dashboard/reports/settings, gated by `canAccess()`). `CommandPalette::search()` merges providers, ranks per group, groups in order (Commands · Resources · Pages · Records) capped at 8/group. Verified permission-scoped by `CommandPaletteTest` (admin sees Users/Payslips/Dashboard by query; an Employee-role user sees Payslips but not Users). Suite: 145 passed.
- [x] **Phase 3 — Record provider.** `RecordProvider` bridges to each resource's `getGlobalSearchResults($query)` (a `Collection<GlobalSearchResult>`), gated by `canGloballySearch()` (policy + searchable attributes) so results are current-tenant only. Runs only for queries ≥ 2 chars; resource nav label as subtitle; title-scored for ranking. Verified by `CommandPaletteTest` (finds a seeded Product by name; ignored for 1-char queries). Suite: 146 passed.
- [x] **Phase 4 — Command provider.** `CommandProvider` adds the **Commands** group: **Switch to {company}** (one per other company from `getTenants()`, current excluded, tenant-aware URL), **Toggle theme** (client-side localStorage + `theme-changed` event), **Log out** (client-side POST via a hidden CSRF form using `Filament::getLogoutUrl()`). `command` field threads through `search()`; the Blade `run()` handles `logout`/`toggle-theme`, else navigates. (Dashboard/Settings already covered by the Page provider.) Verified by `CommandPaletteTest`. Suite: 147 passed.
- [x] **Phase 5 — Polish.** Empty-state **Recents** (localStorage, top 5, deduped, shown as a "Recent" group when the query is empty). Topbar **"Search… ⌘K"** trigger button (render hook `TOPBAR_END`) that dispatches `open-command-palette`. **Sticky, blurred group headers.** **A11y:** `role="combobox"` input with `aria-activedescendant`, `role="listbox"` container, `role="option"` + `aria-selected` rows. `⌘Enter` (new tab) already wired in Phase 1; dark-mode uses Filament tokens throughout. Suite: 147 passed.

---

## 9. Testing

- **Unit:** each provider returns only authorized items — `ResourceProvider` for an `Employee`-role user excludes admin-only resources; `RecordProvider` returns only current-tenant records (use the `InteractsWithTenant` trait + a second company to prove no leakage).
- **Livewire:** `Livewire::test(CommandPalette::class)->call('search', 'pay')->assertSee('Payslips')`; assert a non-permitted resource is absent.
- **Tenant isolation:** company A's records never appear while company B is current.
- Keep the suite green (currently 142 passing) and add a `CommandPaletteTest`.

---

## 10. Alternatives

- **Buy [Filament Spotlight Pro](https://denniskoch.dev/projects/filament-spotlight-pro/docs/)** — auto-discovery, nested resources, global-search integration, theme-aware, maintained. Fastest path; verify it plays well with **path-based multi-tenancy + spatie teams** before committing (our per-company permission scoping is the key compatibility risk).
- **Filament's built-in global search** already exists (topbar). The palette is a superset: it adds pages, actions, and commands, plus keyboard-first UX.

## 11. Risks / notes

- **Permission gates must run under the current team** — they do, because the palette renders inside a tenant-scoped request where `SyncSpatieTenant` has set the team id. Verify in tests.
- **Performance:** resource/page discovery is cheap (class metadata); record search hits the DB — debounce (150ms) and only run the RecordProvider when query length ≥ 2.
- **No external assets** — inline the Alpine component; use Filament's existing Alpine/Livewire runtime (already loaded), so nothing new to bundle.
