# Faster Page Loads — SPA Navigation and Render Cost — Plan

**Status:** Phases 0–5 implemented (2026-08-13); Phase 6 outstanding — see [What landed](#what-landed)
**Created:** 2026-08-13

Goal: make moving around the panel feel instant. Today every click on a sidebar entry is a full
browser navigation: the document is thrown away, ~750 KB of CSS and JS is re-parsed, Alpine and
Livewire boot from scratch, and the server rebuilds a navigation tree of 101 components — for a
page whose shell is byte-for-byte the one already on screen.

The work splits in two, and they are worth separating because they pay off differently:

- **Client**: stop reloading the document. Livewire's `wire:navigate` swaps the body over `fetch`
  and keeps the parsed CSS, the booted Alpine, the WebSocket and the scroll position. This is the
  single biggest visible win and it is one line of configuration plus an audit.
- **Server**: stop doing work nobody asked for. A page load costs eleven `count(*)` queries for
  sidebar badges nobody has looked at yet, a session write to disk, a cache read from MySQL, and —
  in production without `optimize` — a re-parse of every config and route file.

## Not doing

- **Laravel Octane.** It is the obvious answer to a 190 ms bootstrap and it is unavailable:
  production hosting cannot run persistent processes, which is the same constraint that put Reverb
  on its own VPS (`docs/realtime-notifications-plan.md:10`). OPcache + `optimize` is the version of
  this we can have.
- **An SPA front end** (Inertia/Vue/Livewire-free rewrite). The panel is Filament; replacing its
  rendering means replacing the application. `wire:navigate` gives the part of that people actually
  feel — no white flash, no asset re-parse — for none of the cost.
- **Shrinking the theme CSS.** `public/build/assets/theme-*.css` is 649 KB, which is in line with
  Filament's own bundle (`public/css/filament/filament/app.css`, 599 KB) and is *not* loaded
  alongside it: `viteTheme()` replaces the default theme rather than adding to it
  (`vendor/filament/filament/src/Panel/Concerns/HasTheme.php:50-75`). Compression and cache headers
  (Phase 5) take it to ~70 KB on the wire and to zero on the second page. Tree-shaking Filament's
  own CSS is a large risk for a smaller win.

## Facts verified against this codebase and vendor source (not assumed)

**SPA mode exists and is off.**
- `Panel::spa(bool $condition = true, bool $hasPrefetching = false)` and `spaUrlExceptions()`
  (`vendor/filament/filament/src/Panel/Concerns/HasSpaMode.php:18-33`), forwarded to the view
  manager when the panel boots (`vendor/filament/filament/src/Panel.php:99`).
- `AdminPanelProvider::panel()` calls neither (`app/Providers/Filament/AdminPanelProvider.php:93-224`).
- With it on, every link Filament renders through `generate_href_html()` gains `wire:navigate`, or
  `wire:navigate.hover` when prefetching is enabled (`vendor/filament/support/src/helpers.php:138-158`).
  Only *app* URLs are affected, and `spaUrlExceptions` is matched with `str()->is()` patterns
  (`vendor/filament/support/src/View/ViewManager.php:129-144`).
- Filament also tags its own asset tags with `data-navigate-track` when SPA mode is on
  (`vendor/filament/support/src/Assets/Js.php:126`, `Css.php:51`), so a deploy that changes an asset
  URL forces a real reload instead of running new markup against old JS.

**Half the shell already navigates this way, and the rest does not.** The domain rail hard-codes
`wire:navigate` on each domain link (`resources/views/filament/partials/domain-rail.blade.php:31`),
so switching domain is already a soft navigation while clicking an entry *inside* the column is a
full reload. The partial that opens the active branch was written with soft navigation in mind and
already reconciles Alpine's store after a swap
(`resources/views/filament/partials/sidebar-open-active-group.blade.php:60-68`). Livewire re-executes
body scripts on every navigation unless they carry `data-navigate-once`
(`vendor/livewire/livewire/dist/livewire.esm.js:13067-13077`), so that partial keeps working — this
is the thing most likely to break under SPA mode elsewhere, and here it is already handled.

**Custom Blade emits raw anchors that SPA mode cannot reach.** `generate_href_html()` only helps
links Filament renders. These are ours and would stay full page loads:
`resources/views/filament/pages/reports.blade.php:6`,
`resources/views/filament/pages/user-manual.blade.php`,
`resources/views/filament/employee-change-diff.blade.php`.

**Sidebar badges cost a `count(*)` each, on every request, whether or not the branch is open.**
Filament evaluates them eagerly while building the item — `->badge(static::getNavigationBadge(), ...)`
is a value, not a closure (`vendor/filament/filament/src/Pages/Page.php:158`). Eleven resources
define one: Attendance ×2, Expenses, Recruitment, CRM ×3, Lifecycle, Support, Leave, Employees.
Several are not plain counts — `LeaveRequestResource::getNavigationBadge()` counts through
`getEloquentQuery()`, which for a non-privileged user adds a `whereIn` over accessible employee ids
(`app/Modules/Leave/Filament/Resources/LeaveRequests/LeaveRequestResource.php:50-62`).

**The navigation itself is built once per request and that was already fought for.**
`NavigationSnapshot` memoises the unfiltered tree so the rail and the column share one build
(`app/Filament/Navigation/NavigationSnapshot.php:22-37`), and `DomainNavigationManager::get()` reads
that snapshot rather than rebuilding (`app/Filament/Navigation/DomainNavigationManager.php:58-81`).
Anything this plan does must not resurrect a second build — and note the class comment's warning
about `scoped` vs `singleton`: caching navigation *across requests* is a cross-tenant leak.

**Infrastructure is one step behind what is installed.**
- `CACHE_STORE=database` (`.env`) while Redis is installed, configured and already carrying the
  queue (`QUEUE_CONNECTION=redis`). Every cache read is a MySQL round trip — including spatie's
  permission list, which is read while the sidebar is assembled.
- `SESSION_DRIVER=file`: a write plus a lock per request, on the same disk as everything else.
- No OPcache at all on the CLI PHP here (`opcache_get_status` undefined), and `bootstrap/cache/`
  holds only `packages.php` and `services.php` — no config, route, event or view cache. Measured
  framework bootstrap on this machine: **190 ms**.
- No deploy script runs `optimize`, `filament:optimize` or `icons:cache` — `deploy/` contains only
  the Reverb VPS reference config.

**Nothing in the app defers anything.** Zero uses of `deferLoading()`, `->lazy()` or `poll()` across
`app/` — `Table::deferLoading()` exists (`vendor/filament/tables/src/Table/Concerns/CanDeferLoading.php:11`).
Widgets are the exception and are already right: `CanBeLazy::$isLazy` defaults to `true`
(`vendor/filament/support/src/Concerns/CanBeLazy.php:9`) and no widget in `app/` overrides it.

**Notifications poll every 60 s on top of a working WebSocket.** `databaseNotificationsPolling('60s')`
(`app/Providers/Filament/AdminPanelProvider.php:143`) while `config/filament.php:17-30` has a filled
Echo block, so every open tab makes a Livewire round trip a minute for something Reverb already
pushes.

**Measurement is blocked locally by a data problem, not a code one.** Every panel page in the dev
database returns 500: `There is no permission named 'HolidayView' for guard 'web'`, thrown while the
rail renders. The landlord `permissions` table holds 149 rows and that is not one of them — the
seeder has not been run since the permission was added. Phase 0 fixes this first, because until it
is fixed the only thing measurable is the size of an Ignition error page.

## Phase 0 — Make it measurable

Nothing below should be merged on the strength of an argument. Establish the baseline first.

1. `php artisan db:seed --class=PermissionSeeder`, then flush every company's copy via
   `App\Support\PermissionCache::flushEverywhere()` — the class exists precisely because the
   per-tenant cache prefix makes `permission:cache-reset` clear the copy nobody reads
   (`app/Support/PermissionCache.php`).
2. Add `tests/Feature/PanelPerformanceTest.php`: a query-budget guard, not a stopwatch. Boot the
   panel as a seeded administrator with `DB::listen`, request the dashboard and one resource index,
   and assert the query count is under an explicit ceiling with the recorded SQL in the failure
   message. Timings vary per machine; query counts do not, and a regression here is always a
   regression.
3. Record the baseline in this document's Results section: queries and server time for dashboard,
   a resource index (Employees), and the Reports hub.
4. Client-side baseline with Debugbar (already a dev dependency) and one Chrome trace per page:
   transferred bytes, scripting time, Largest Contentful Paint.

## Phase 1 — SPA navigation

The visible win. Everything here is reversible by deleting one method call.

1. `->spa(hasPrefetching: true)` in `AdminPanelProvider::panel()`, with a comment saying what
   prefetching costs: `wire:navigate.hover` fetches on hover, so a user sweeping the sidebar pulls
   several pages. Given every panel page renders the full navigation server-side, this is a real
   load; if Phase 2 does not land first, ship with `hasPrefetching: false` and turn it on after.
2. `->spaUrlExceptions([...])` for everything that is a download rather than a page — a soft
   navigation to one of these leaves the browser holding a PDF it will not render:
   - `*/filament/exports/*/download`, `*/filament/imports/*/failed-rows/download`
   - `*/reports/*` (the DomPDF statements: balance sheet, P&L, cash flow, trial balance, aged
     receivables/payables, tax summary, invoice PDFs)
   - `*/api/my-payslips/*/pdf`
   - the tenant file route (`App\Support\TenantStorage::URL_PREFIX`, `routes/web.php:20`)
   - `*/platform*` — a different panel with different assets; let it load properly.
3. Add `wire:navigate` to our own anchors: `reports.blade.php`, `user-manual.blade.php`,
   `employee-change-diff.blade.php`. Use `\Filament\Support\generate_href_html()` rather than a
   hard-coded attribute, so these follow the panel's setting and its exceptions instead of a second
   copy of the decision. (The rail's hard-coded `wire:navigate` should move to the same helper for
   the same reason.)
4. Audit every inline `<script>` rendered on panel pages for re-execution: they now run again on
   each navigation. `sidebar-open-active-group.blade.php` is safe by construction (it only ever
   opens, and syncs Alpine's store). Check `command-palette.blade.php`, `report-controls.blade.php`,
   `impersonation-banner.blade.php`, `saved-views-bar.blade.php` and `account-register.blade.php`
   for listeners bound to `document` that would stack up across navigations — the symptom is a
   handler firing twice after four clicks, never on first load.
5. Confirm the impersonation banner and the notification bell survive a swap: both are render-hooked
   into `BODY_START`/`BODY_END`, so they are inside the replaced body and re-render each time. That
   is correct behaviour, but it means the bell's unread count re-queries per navigation — Phase 3
   deals with it.

**Tests.** Extend `PanelPerformanceTest` (or a new `SpaNavigationTest`): assert
`FilamentView::hasSpaMode()` is true for the admin panel, assert `hasSpaMode($url)` is false for one
URL from each exception family (a regression here silently breaks PDF downloads), and assert a
rendered sidebar link contains `wire:navigate`.

## Phase 2 — Stop paying for the sidebar on every request

1. **Badges.** Give every `getNavigationBadge()` a short-lived per-user, per-company cache — 60 s in
   a shared helper, e.g. `App\Support\NavigationBadge::count($key, fn () => ...)`, keyed on
   `[company, user, resource]`. A pending-approvals count that is a minute stale is not a bug; eleven
   `count(*)` queries on every page is. The key must include the user: `LeaveRequestResource`'s
   count is scoped by accessible employees, so a shared key would leak one manager's figure to
   another. Redis (Phase 4) makes this cheap; on the database store it trades eleven counts for
   eleven cache reads and is barely worth doing, so land Phase 4 first or together.
2. **Invalidation.** Rather than events on eleven models, let the TTL do it and forget the key on the
   write path where it is trivial (approve/reject actions already run in one place per module). Do
   not build a subscriber for this.
3. **Duplicate work inside one request.** With Phase 0's harness, look for the same statement twice:
   the tenant lookup by slug, the `employees where user_id = ?` lookup behind employee-access checks,
   and the notifications `count(*)`. `App\Support\Modules` already memoises module state per request
   (`app/Support/Modules.php:23-31`); `App\Support\EmployeeAccess` and `EmployeeOptions` should be
   checked for the same treatment.
4. **Do not cache the navigation tree across requests.** Stated here so nobody proposes it later:
   the tree depends on company, licence and role, and `NavigationSnapshot`'s comment explains what
   caching it across requests would leak.

## Phase 3 — Defer what is below the fold

1. `deferLoading()` on the heavy resource tables (Employees, Attendance days, Journal entries,
   Invoices, Tickets) so the page shell paints before the query runs. Applied per table rather than
   globally: on a 20-row table it adds a round trip for nothing.
2. Drop `databaseNotificationsPolling()` now that Echo is configured, or lengthen it to `300s` as a
   socket-drop fallback. One Livewire round trip per minute per tab, across every open tab in the
   company, for data that is already pushed.
3. Leave widgets alone — they are lazy by default and no widget overrides it.
4. Check the ⌘K palette's initial render: `CommandPalette::search()` is only called from Alpine on
   open (`app/Filament/Livewire/CommandPalette.php:29`), so the per-page cost is the 180-line view
   and nothing else. If that view turns out to render resource lists eagerly, mark the component
   `#[Lazy]`; otherwise no change.

## Phase 4 — Infrastructure the app is already provisioned for

1. `CACHE_STORE=redis` in `.env` / `.env.example`. Verify against multitenancy first:
   `PrefixCacheTask` prefixes keys per tenant (`app/Support/PermissionCache.php` documents the
   mechanism), which is store-agnostic — but the assertion belongs in a test, not in a paragraph.
2. `SESSION_DRIVER=redis`. Note `SESSION_ENCRYPT=false` and that sessions then live in the same
   Redis as the queue: use a separate database index, and confirm Horizon's `redis` connection is
   not flushed by anything that would take sessions with it.
3. OPcache on production PHP: `opcache.enable=1`, `opcache.memory_consumption=256`,
   `opcache.max_accelerated_files=20000`, `opcache.validate_timestamps=0` (with a deploy that
   clears the cache — otherwise a release silently serves the previous one).
4. A deploy script under `deploy/` that runs, in order: `composer install --no-dev
   --optimize-autoloader`, `php artisan optimize` (config, route, view, event), `php artisan
   icons:cache`, `php artisan filament:optimize` (components + Blade icons — both commands exist:
   `vendor/filament/filament/src/Commands/CacheComponentsCommand.php`,
   `vendor/filament/support/src/Commands/OptimizeCommand.php`), `npm ci && npm run build`. This is
   the cheapest item in the plan and the one with the largest constant-factor effect: 190 ms of
   bootstrap per request, on every request.

## Phase 5 — Transport

1. gzip or brotli on `text/css`, `application/javascript`, `text/html`. 649 KB of theme CSS is ~70 KB
   compressed; without it, SPA mode's benefit is partly spent re-downloading assets on first visit.
2. `Cache-Control: public, max-age=31536000, immutable` for `/build/*` (content-hashed by Vite) and
   `/js/filament/*`, `/css/filament/*` (versioned by Filament's asset URLs).
3. Confirm Filament's per-component JS stays lazy: `code-editor.js` (962 KB), `markdown-editor.js`
   (511 KB), `rich-editor.js` (488 KB) and `file-upload.js` (364 KB) are loaded on demand by Alpine.
   If any of them appears in the initial payload of a page without that field, that is a bug worth
   more than everything else in Phase 5 combined.

## Phase 6 — Query hygiene (ongoing, not a milestone)

1. `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider` — it is not set
   today. Expect it to fail loudly in several tables at first; that is the point, and it is why this
   is last.
2. Fix what it finds with `->with()` on the table queries rather than by disabling the guard.
3. Index whatever the badge counts filter on (status columns, `employee_id`), once Phase 0 has shown
   which of them are actually slow rather than merely numerous.

## Risks

- **SPA mode and third-party JS.** Anything that binds to `document` on load and is not idempotent
  will stack across navigations. The audit in Phase 1.4 is the mitigation; the failure mode is
  subtle, so it wants a manual pass through the app (open ten pages in a row, then use a modal, a
  file upload, an export, and the palette).

  *Audited 2026-08-14, and the list in Phase 1.4 was wrong:* `command-palette.blade.php`,
  `report-controls.blade.php`, `impersonation-banner.blade.php`, `saved-views-bar.blade.php` and
  `account-register.blade.php` contain no `<script>` tag at all. The only two inline scripts rendered
  on panel pages are `domain-rail.blade.php` (seeds the sidebar's collapsed state in localStorage) and
  `sidebar-open-active-group.blade.php`, and both are idempotent by construction — one only writes a
  key that is already absent, the other only ever *opens* a branch. Nothing binds to `document`. The
  manual pass is still worth doing for Filament's own components; this application's scripts are clear.
- **Redis is now a hard dependency for signing in.** Phase 4 moved sessions there from files, so Redis
  being down no longer means a slower cache — it means every session is gone and nobody can log in.
  The queue was already on Redis, but a queue that stops is a delay and a session store that stops is
  an outage. Whatever monitors Redis should page someone, and the separate database index for sessions
  matters mainly so a `FLUSHDB` aimed at the queue does not take the logins with it.
- **Prefetching amplifies server cost.** Hover-prefetch multiplies full page renders by however many
  links a user sweeps past. Ship it after Phase 2, or not at all if the badge work slips.
- **Cross-tenant caching.** Every cache key introduced in Phase 2 must contain the company id *and*
  the user id. This application has already been bitten by a per-tenant cache prefix behaving
  differently from what the code assumed (`app/Support/PermissionCache.php`), and the failure mode is
  a data leak rather than a wrong number on screen.
- **`validate_timestamps=0` without a deploy hook** serves the previous release forever. Only turn
  it on together with the deploy script.

  *Still outstanding as of 2026-08-14, and this one is live:* `deploy/deploy.sh` does not reset OPcache.
  It ends by **printing** `sudo systemctl reload php8.3-fpm` for the operator to run. So the mitigation
  for "serves the previous release forever" is a person reading the last four lines of a deploy log,
  which is not a mitigation. Either put the reload in the script (and fail the deploy if it does not
  succeed) or leave `validate_timestamps` at its default and accept the stat calls. Shipping the ini
  file and the reminder together is the one combination that silently serves stale code.

## Results

Measured by `tests/Feature/PanelPerformanceTest.php`, which is also the guard: the budgets in that
file are these numbers with a little headroom, so a regression fails the suite rather than being
noticed months later. Counts are statements per request as a seeded administrator with the whole
navigation visible.

| Page | Before | After (cold cache) | After (warm, second page within the minute) |
| --- | --- | --- | --- |
| Dashboard | 25 | 21 | 10 |
| Employees index | 27 | 24 | 13 |
| Reports hub | 22 | 19 | 8 |

Where the difference went:

- **11 statements** on every page were sidebar badge counts, now cached per company and per user for
  a minute (`App\Support\NavigationBadge`). They run on the first page load of the minute and on
  none of the ones after it, which is the case that matters — nobody loads one page.
- **4 statements** were `Company::all()`, run once for each place Filament asks who the user may
  switch to. `User::getTenants()` now answers from an instance memo.
- The rest is unchanged and is mostly the permission list, the module map and the tenant lookup —
  all cache reads in production now that the cache is Redis rather than MySQL.

Not captured in the table, because a query count cannot see them:

- Soft navigation. Moving between pages no longer re-downloads or re-parses the 649KB stylesheet and
  the ~250KB of JavaScript, and no longer re-boots Alpine, Livewire and the Reverb socket. This is
  the largest thing a person actually feels and it does not show up as a single statement.
- `optimize` and OPcache remove ~190ms of framework bootstrap per request (measured on a developer
  machine with neither).
- `deferLoading()` on the long tables moves the table's own query out of the initial render, so the
  page paints before it runs rather than after.

## What landed

| Phase | State |
| --- | --- |
| 0 — Measurable | `tests/Feature/PanelPerformanceTest.php`, budgets and warm/cold assertions |
| 1 — SPA navigation | `->spa()` + `spaUrlExceptions` in `AdminPanelProvider`, `tests/Feature/SpaNavigationTest.php` |
| 2 — Sidebar cost | `App\Support\NavigationBadge` + `tests/Feature/NavigationBadgeTest.php`; `User::getTenants()` memo |
| 3 — Deferral | `deferLoading()` on Employees, Attendance days, Journal entries, Invoices; notification polling 60s → 300s |
| 4 — Infrastructure | `CACHE_STORE=redis`, `SESSION_DRIVER=redis` on its own database, `deploy/deploy.sh`, `deploy/php/opcache.ini` |
| 5 — Transport | `deploy/nginx/assets.conf` |
| 6 — Query hygiene | `Model::preventLazyLoading(! isProduction())` + the eight fixes below |

## Phase 6 — done 2026-08-14

The guard is on in `AppServiceProvider::boot()`, in the `! $this->app->isProduction()` form. It
replaces an unconditional `preventLazyLoading(true)` left over from the probe — that version throws in
production too, where a relation nobody happened to exercise in development would take a customer's
page down rather than serve it a little slower.

The 20+ failures resolved to **eight relationships**, and the shape is worth naming because the next
one will look identical: a *model method* that reads a relation, called from a table row, a service, a
report and a test, where only one of those callers eager-loaded it.

| Where | Relation | Fix |
| --- | --- | --- |
| `Account::descendants()`, `getCalculatedBalanceAttribute()` | `children` | walk `childrenRecursive` — one query per depth instead of one per node |
| `Payment::accountProblem()`, `beneficiaryDetails()`, `isReleasable()` | `payable`, `payable.bank`, `payable.user`, `payslip` | `loadMissing` in the model; `morphWith` in the page that lists them |
| `LoanService::recordInstalment()` | `loan` | `loadMissing('loan')` |
| `OpportunityStageHistory::isBackwards()` | `fromStage`, `toStage` | `loadMissing` both |
| `Employee::fullName()` | `user` | `loadMissing('user')` — a safety net, not a fix; see below |
| `EditRole::mutateFormDataBeforeFill()` | `permissions` | `loadMissing` on the route-bound record |
| `PayComponentsTable` | `account` | `modifyQueryUsing(->with('account'))` |
| `HelpIsRoleAwareTest` | `permissions` | `->with('permissions')` — that violation was in the test |

Two limits, stated so a green suite is not read as more than it is:

- `Employee::fullName()` is called from selects and columns across the panel. `loadMissing` there makes
  it explicit and non-fatal, not cheap: over a list it is still a query per employee, and the guard is
  now *satisfied* so it will not point at the places that should eager-load `user`. That is the honest
  limit of this tool.
- The guard only sees what the suite exercises. Screens without tests lazy-load until somebody opens
  them in development.

**Phase 6.2 (indexes) is a no-op.** Every column the eleven badge counts filter on is already indexed:
`status` across six tables, plus `stage`, `outcome`, `expires_on`, `completed_at`/`due_on` and `date`.
Checked against the tenant migrations.

**Phase 6.3 (prefetching) is measured, not decided.** A prefetch is one warm render, and a warm render
now costs **6 queries, ~56 ms, 301 KB of HTML** (median of five; test environment, empty tables). The
argument against it was 23-query pages; they are 6. What remains is a bytes question rather than a
database one — a five-link sweep pulls ~1.5 MB raw / ~175 KB compressed, and ~118 KB of every page is
the rail's flyouts. Left off: that decision wants production traffic, not another laptop number.

## Two guards added 2026-08-14

Both close holes in this plan's own measurements rather than making anything faster.

1. **Row scaling** (`test_a_pages_query_count_does_not_grow_with_its_rows`). Every other number in this
   document was measured against **empty tables**, where a query per row multiplies by nothing — so a
   per-row lookup passes every budget here. Worse, since Phase 6 the lazy-loading guard cannot catch it
   either: the fixes that satisfy it include `loadMissing` inside model methods, which is explicit,
   legal, and still one query per row when the method is called down a list. This renders a table with
   two rows and then with ten and asserts the count barely moves. Verified by removing the eager load
   it guards and watching it fail.
2. **Payload size** (`test_the_rendered_pages_stay_within_their_size_budget`). Nothing was watching
   bytes: the domain rail's flyouts added ~118 KB to every page and no test noticed. Under SPA mode that
   is the payload of every navigation and under hover prefetching the payload of every *hover*, which
   makes it the number most likely to undo Phase 1's win. Ceilings are the measured 207/301/231 KB plus
   headroom, uncompressed so they fail on a laptop.

### Still outstanding

- **The employee name is an N+1 in 14 tables and every employee select.** `display_label` resolves
  through `Employee::fullName()`, which reads `user` — and `TextColumn::make('employee.display_label')`
  eager-loads `employee` but not `employee.user`, so the name costs a query per row. Fourteen files use
  that column; `App\Support\EmployeeOptions::search()` has the same shape for select options. Phase 6's
  `loadMissing` made this legal rather than fatal, which is why it now needs finding by hand.
  The one-line candidate is `protected $with = ['user']` on `Employee`: it fixes all fifteen sites at
  once, and costs one extra eager load on employee queries that never read a name. Worth measuring
  against the row-scaling test above, extended to the attendance-days table, before committing to it.
- **Nothing measures this in production.** The only observability package installed is
  `barryvdh/laravel-debugbar`, which is dev-only — no Pulse, no APM, no per-request query log. Every
  figure in this document comes from a laptop running sqlite against empty tables, while production is
  MySQL with a per-tenant connection switch on top. Until something records query count and duration
  per route in staging or production, the Results table above describes a machine nobody uses.
- **The 60-second badge TTL may not match how this application is used.** The headline warm figures
  assume the next page arrives within a minute. This is an accounting and payroll application: reading
  a P&L, filling in a leave request, reviewing a payslip are all easily longer than that, and every
  navigation after such a pause pays all eleven counts again. The realistic figure for intermittent use
  is the *cold* column, not the warm one. Either measure real think-time or raise the TTL — a
  pending-approvals count five minutes stale is no more misleading than one a minute stale.
- Turning hover prefetching on, per above — a judgement about traffic, with the cost now known.
- Automating the OPcache reset in `deploy/deploy.sh`, per the Risks note. Currently a printed reminder.
- A test asserting every permission named in `app/` exists in `PermissionSeeder`. Not a performance
  item, but Phase 0 was blocked by a seeder 96 permissions behind whose only symptom was a 500 on
  every page.
- A client-side baseline (Phase 0.4), still never taken: LCP, transferred bytes and scripting time for
  a cold load and a same-session navigation. The goal of this plan is that moving around *feels*
  instant, and that is the only measurement of it.
