# Right-Click Context Menu in Tables — Plan

**Status:** Phases 0-4 done — every table, bulk, keyboard, touch and an off switch. Phase 5 outstanding.
**Created:** 2026-08-14
**Inspiration:** [Filament Examples — Right-Click Menu in Table](https://filamentexamples.com/project/filament-right-click-menu-in-table),
which demonstrates the MIT package `leek/filament-right-click` (`^4.0 || ^5.0`, latest 1.5.2,
released 12 Aug 2026) and its two methods: `->contextMenuActions()` and `->contextMenuBulkActions()`.

Goal: right-click a row in any of this application's 51 tables and get that row's own actions where the
cursor is — and, when rows are selected, the bulk actions for the selection. Every table, one
implementation, no per-table configuration.

One finding decides the whole design, and it is verified against vendor source rather than assumed:

**Filament already computes and renders each row's authorized action set.** The row loop resolves
`$recordActionsByRecordKey[$recordKey] ?? $reduceVisibleRecordActions($record)`
(`vendor/filament/tables/resources/views/index.blade.php:1094-1095`), and that reducer clones each of
`getRecordActions()` (`vendor/filament/tables/src/Table/Concerns/HasRecordActions.php:100`), binds the
record to it and **drops anything `isHidden()`** (`index.blade.php:149-169`) — so visibility and
authorization are already applied, per record, and the result is cached per record key. Those actions are
rendered into a `.fi-ta-actions` container inside the row (`:1342`), and each button carries Filament's
own mount call, `mountAction(name, arguments, context)` (`vendor/filament/actions/src/Action.php:519`).

So **the context menu is a *view* of what the row already renders, never a second list of actions.** That
one decision removes the feature's main hazard — a menu offering an action the user is not allowed to
run — because there is no second authorization path to keep in step. It also means no table has to be
edited to gain the menu.

## The cost this feature has, stated first

Suppressing the browser's context menu takes away things people use on list screens every day, and the
plan is only worth reading if it answers for them.

**Most rows in this application are real links.** When `recordUrl` is set — Filament's default whenever a
resource has an edit or view page, and only four places in this codebase override it — the row's content
is an `<a href>` built by `generate_href_html()` (`index.blade.php:1274-1275`). Today a user middle-clicks
or right-clicks → *Open link in new tab*, and on a list of invoices that is the fastest way to work.
`event.preventDefault()` on `contextmenu` removes it.

Therefore, non-negotiably:

1. The menu itself carries **Open**, **Open in new tab** (a genuine `<a target="_blank">`, not a JS
   `window.open`, so the browser's own modifiers and middle-click still behave) and **Copy link**.
2. Middle-click and ⌘/Ctrl-click on the row keep working untouched — the handler is on `contextmenu`
   only.
3. There is an **escape hatch**: a per-user preference to switch the menu off, and holding `Shift` while
   right-clicking always yields the native menu. Firefox honours Shift natively; Chromium does not, so
   our handler checks `event.shiftKey` and returns early. That check is two lines and is the difference
   between a power feature and a complaint.
4. Where a row has **no** URL — `recordAction` instead, which renders a `<button>` with
   `mountTableAction()` (`index.blade.php:1290-1298`), as the Activity Log list deliberately does — the
   link items are absent rather than dead. The menu reflects the row's actual mode.

## Build or buy

Following the pattern of `docs/advanced-tables-implementation.md` and
`docs/command-palette-implementation.md`, both of which name the paid/packaged alternative rather than
pretending it does not exist.

| | `leek/filament-right-click` | Build in-house |
|---|---|---|
| Cost | MIT, free, and actively released (1.5.2, Aug 2026) | Our time |
| Filament v5 | Declared `^4.0 \|\| ^5.0` | n/a |
| API | `->contextMenuActions([...])`, `->contextMenuBulkActions([...])` per table | One `Table::configureUsing()`, zero per-table code |
| Action list | **Declared per table** — a second list to keep in step with `recordActions()` | Derived from the row's rendered, already-authorized actions |
| Link items (open / new tab / copy) | Not described | Required here (see above) |
| Our constraints | Unknown: tenant scoping, `wire:navigate`/SPA re-execution, `StoreAccess`-style row scoping, the 68px flyout positioning conventions | Built against them |

**Recommendation: try the package first, in a spike, and be willing to drop it.** It is free and MIT, so
an afternoon establishes whether it survives this application's SPA navigation and whether its
per-table declaration is acceptable. The reason to expect we build our own is the fourth row: a
declared list per table is 51 lists that drift from `recordActions()`, and the whole value of this
feature is that it needs no per-table work. Phase 0 decides it with evidence rather than by argument, and
the phases after it are written so that either answer keeps them.

## Not doing

- **A second definition of what a row can do.** No `contextMenuActions()` in this codebase's tables. If
  an action should be in the menu it belongs in `recordActions()`, where it is also visible to somebody
  who has never right-clicked anything.
- **A right-click menu outside tables.** Not on the sidebar, the dashboard widgets, or the Reports
  explorer rows. The gesture has to mean one thing, and "the actions for the row under the cursor" is
  that thing.
- **Nested submenus.** Grouped sections with separators, yes — a group of actions is already an
  `ActionGroup` in most of these tables. A submenu that opens on hover inside a menu that opened on
  right-click is two flyout layers and a positioning problem for one saved click.
- **Replacing the row action buttons.** The `.fi-ta-actions` column stays exactly as it is. The menu is
  an accelerator, and a feature reachable *only* by right-click is unreachable on a tablet and invisible
  to a new user.
- **Custom actions that only exist in the menu** (an "advanced" set hidden from the table). That is how a
  permission gap gets introduced: the menu's items must be the row's items.

## §1 How it works

1. **One delegated listener per table**, on the table container, not per row. 51 tables and pages of 25
   rows each; a listener per row is a needless cost, and this codebase has already paid for per-row
   mistakes twice (`/payroll-runs`, `/tax-rates`).
2. **Finding the row and its key.** The row element is `.fi-ta-record` and carries
   `wire:key="{componentId}.table.records.{recordKey}"` (`index.blade.php:1222`), which is parseable but
   is Filament's internal shape. So we also set `data-record-key` ourselves through
   `extraRecordLinkAttributes()` — a documented API on the table
   (`vendor/filament/tables/src/Table/Concerns/HasRecordUrl.php:88`) — applied once in
   `Table::configureUsing()`. Read the attribute, fall back to parsing `wire:key`, and if neither
   resolves, do nothing and let the native menu open. **Failing open is the rule**: a menu that cannot
   identify its row must not eat the gesture.
3. **Building the menu from the row.** On open, read the row's `.fi-ta-actions` descendants — buttons and
   anchors that Filament has already rendered with the correct `mountAction(...)`/`href` — plus any
   `ActionGroup` trigger's panel contents, and present their label, icon, colour and handler in the
   floating menu. Clicking a menu item invokes the original element's handler. No new Livewire method,
   no new authorization decision, nothing to keep in step.
4. **Selection wins over the row.** If the right-clicked row is part of a selection
   (`isRecordSelected(key)` — Alpine state Filament already exposes on the table,
   `index.blade.php:1236`), show the **bulk** actions from `toolbarActions()` for the whole selection,
   labelled with the count ("Delete 6 selected"). Right-clicking *outside* the selection clears it and
   shows that row's own actions, which is what every file manager does and what people expect.
5. **Positioning.** Fixed, anchored to the pointer, flipped when it would leave the viewport — the same
   problem the domain-rail flyouts already solved, and the same rule applies: the menu must never be the
   thing that makes the page scroll horizontally. `resources/css/filament/admin/theme.css` already
   carries those conventions and this reuses them.
6. **Dismissal.** Escape, any click elsewhere, scroll, a second right-click elsewhere, and Livewire
   navigation. Also on `livewire:navigated`, because a menu left floating over a page that has been
   swapped underneath it is a bug this application would produce in exactly one way — see §2.

## §2 Delivery, and the SPA trap

This codebase's inline scripts are all in render-hook partials and are **idempotent by construction** —
`docs/page-load-performance-plan.md` records the audit: Livewire re-executes body scripts on every
`wire:navigate` unless they carry `data-navigate-once`, and the two scripts that exist only ever write an
absent localStorage key or *open* a branch. A context menu is bigger than either and would stack a
listener per navigation if written the same way.

So it ships as a **registered Filament JS asset**, not an inline partial: an Alpine component in a Vite
entry, registered through `FilamentAsset::register()` in the panel provider. Nothing in `app/` does this
yet — `vite.config.js` has three inputs (`app.css`, `app.js`, the admin theme) and `app.js` is only used
by the stock welcome page — so this establishes the pattern, and it is the right one because Filament
tags its own assets with `data-navigate-track` under SPA mode, which makes a deploy that changes the file
force a real reload instead of running new markup against old JS.

Initialisation binds once per table container and is safe to re-run: mark the container, and skip a
container already marked.

## §3 Reach: every table, without touching any table

`Table::configureUsing()` in a service provider, once. Nothing in `app/` uses it today, which is why the
51 table classes each configure themselves — but it is the mechanism that makes "the whole application"
one change rather than 51:

```php
Table::configureUsing(function (Table $table): void {
    $table->extraRecordLinkAttributes(fn ($record): array => [
        'data-record-key' => $table->getRecordKey($record),
    ], merge: true);
});
```

`merge: true` matters even though **no table sets its own link attributes today** — grep says zero, and
that is precisely why the flag is easy to leave off and expensive later: the first table that needs one
would have it silently discarded by this provider, and the symptom would appear in that table rather than
here.

The menu itself needs no configuration at all — it reads the DOM. What the provider adds is the record
key and a container marker.

## §4 Keyboard, touch, and the people who will not use a mouse

A feature bound to a mouse button that has no other route is a feature half the users cannot reach.

- **Keyboard:** the `ContextMenu` key and `Shift+F10` — the platform's own context-menu bindings — open
  the menu for the focused row. Arrow keys move, Enter activates, Escape closes, and focus returns to the
  row. This is also what makes the menu usable with a screen reader, with `role="menu"`/`menuitem` and a
  label naming the record.
- **Touch:** long-press (≈500 ms) with a movement threshold that cancels on scroll, because a long-press
  that fires mid-scroll is worse than no long-press. On touch the menu opens centred rather than at the
  pointer.
- **Nothing menu-only:** every item in the menu exists elsewhere — the actions in `.fi-ta-actions`, the
  link on the row. The menu is faster, never necessary.
- **The command palette is the sibling feature, not a rival.** `docs/command-palette-implementation.md`
  is keyboard-first discovery across the app; this is pointer-first action on one row. Worth landing the
  same vocabulary in both (same labels, same icons) so they do not describe the same operations
  differently.

## §5 Testing what is testable

Livewire tests cannot right-click, so the plan splits deliberately:

1. **Server-side, in PHPUnit:** for a representative table, assert that the actions the menu would offer
   for a record are *exactly* `getRecordActions()` with the hidden ones dropped for that record — the
   same reduction `index.blade.php:149-169` performs — i.e. that the menu's source of truth is the row's.
   And the negative case that matters: a record whose `EditAction` is hidden by policy contributes no edit
   item. This is the authorization test, and it must exist even though the menu is built in JS, because it
   pins the *rule* rather than the rendering.
2. **`Table::configureUsing()` applies everywhere:** one test walking every resource table and asserting
   the record key attribute is present. Add the `merge: true` regression the moment a table sets a link
   attribute of its own — today none does, so the assertion would be vacuous and a vacuous test is worse
   than none.
3. **Interaction, in puppeteer** — already a dependency and already used here to drive real panel HTML:
   right-click a row → the menu opens with that row's items; `Shift`+right-click → no menu (native);
   Escape closes; a selection of three → bulk items naming three; `wire:navigate` to another page → no
   listener stacking and no orphaned menu.
4. **A "no per-row cost" assertion**, in the spirit of `PanelPerformanceTest`: rendering 25 rows adds no
   queries and no per-row DOM subtree for the menu — the menu is one element for the page, created on
   open.

## Phases

- **Phase 0 — Spike the package (half a day).** Install `leek/filament-right-click` on a branch, enable
  it on one table, and answer four questions: does it survive `wire:navigate`; does it require a declared
  action list per table; does it respect an action hidden by policy for one record; does it offer the link
  items. Write the answers here. Ship it if it passes; otherwise continue with §1 and keep the package's
  API shape as the reference.

  > **Phase 0 answered 2026-08-23 — we build our own.** Answered by reading `leek/filament-right-click`
  > v1.5.2 at source (cloned, not installed: three of the four questions are settled by its API and its
  > README, so installing it into this application would have been a cost with no extra evidence behind
  > it). The package is well made, actively released and MIT, and it **documents** the behaviour that rules
  > it out here rather than hiding it — its README's first line calls it "a **static** right-click menu".
  >
  > | Question | Answer |
  > |---|---|
  > | Survives `wire:navigate`? | **Yes**, and it is the right shape. One `document`-level `contextmenu` listener behind a module-scope `listenersBound` flag, nothing per row or per table, and `document.addEventListener('livewire:navigated', closeMenu)`. This is §1.1 and §2 of this plan, arrived at independently — worth noting as corroboration. |
  > | Requires a declared list per table? | **Yes.** `Table::macro('contextMenuActions', array $entries)` takes an explicit list and base64-encodes it into `data-filament-right-click-config` on the table element. It never reads `recordActions()`. |
  > | Respects an action hidden for one record? | **No, in the menu.** `ContextMenuItem::toPayload()` serialises name, label, icon and colour only, and the payload is encoded once per *table* with no record bound — so every row shows the same items. |
  > | Offers the link items? | **No.** No `_blank`, no clipboard, no `href` handling anywhere in its JS — and **no `shiftKey` check either**, so no native-menu escape hatch. |
  >
  > **The third answer is less alarming than it sounds, and the reason is worth recording because it also
  > constrains our own build.** `InteractsWithActions::mountAction()` refuses a disabled action
  > (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:131`), and `isDisabled()` returns true
  > when `isHidden()` is true (`CanBeDisabled.php:24`). So a hidden action **cannot be mounted** — the
  > package's own README says exactly this and it checks out. The failure is a **dead menu item**, not an
  > unauthorized action: the menu closes and nothing happens, silently, unless the action happens to
  > declare `hasAuthorizationNotification()`. That is a real defect for a list screen — a user right-clicks
  > *Delete*, the menu shuts, and nothing tells them why — but it is not the privilege escalation the Risks
  > section fears.
  >
  > **Rejected on the second and fourth answers, not the third.** A declared list per table is 51 lists
  > that drift from `recordActions()`, and the entire value of this feature is that no table is edited;
  > absent link items and absent `Shift` is the complaint the first section of this document is written
  > about. This plan's §1.3 — derive the menu from the row's already-rendered, already-reduced actions — is
  > the only route that gets per-record correctness *and* zero per-table work, and it is available because
  > Filament does that reduction for us (see the premise check below).
  >
  > Two things taken *from* the package as reference, per this phase's own instruction:
  > its listener shape (above), and its vocabulary — `ContextMenuSection`, `ContextMenuSeparator`,
  > `ContextMenuSubmenu`. We want the first two in Phase 5 and have already ruled out the third.
  >
  > **Every premise in this document was re-verified against `filament/filament` v5.7.3 on the same day**,
  > because the plan was written on 2026-08-14 against cited line numbers and a stale premise would have
  > invalidated the design rather than a detail of it. All of them hold, at the exact lines cited: the
  > `$reduceVisibleRecordActions` reducer dropping `isHidden()` actions per record
  > (`index.blade.php:149-169`), the per-record-key cache (`:1094-1095`, `:179`), `.fi-ta-record` with its
  > `wire:key` (`:1222`), the `.fi-ta-actions` container (`:1342`), `isRecordSelected()` (`:1236`),
  > `generate_href_html()` under `$recordUrl` (`:1273-1275`), the `mountTableAction` `<button>` fallback
  > under `$recordAction` (`:1288-1298`), `extraRecordLinkAttributes()` with its `merge` flag
  > (`HasRecordUrl.php:88`), `getRecordActions()` (`HasRecordActions.php:100`), and `mountAction(name,
  > arguments, context)` (`Action.php:519`). The design stands as written.
- **Phase 1 — One table, built.** The Alpine component, the asset registration of §2, the delegated
  listener, menu from `.fi-ta-actions`, link items, positioning and dismissal — proven on the invoices
  table, which has the richest action set in the application.

  > **Phase 1 built 2026-08-23.** — `resources/js/table-context-menu.js`, the menu styles in
  > `resources/css/filament/admin/theme.css`, `FilamentAsset::register()` in `AdminPanelProvider`,
  > `TableContextMenuTest` (12 tests), and `php artisan table-context-menu:smoke` driving
  > `scripts/table-context-menu-smoke.cjs` in headless Chrome.
  >
  > **Proven, in a browser, against the application's own rendered markup** — and the run is the reason
  > this entry is long. Right-clicking the first invoice row offers *Open / Open in new tab / Copy link /
  > Edit / **Issue***; the second offers *… / Edit / **Record Payment / Void / Credit***. Two rows, one
  > table, one render, different menus. That is the design's whole thesis and it is now demonstrated end
  > to end rather than argued.
  >
  > **Two corrections to §1.2, both found by running it and neither findable by reading the view.**
  >
  > 1. **The row is `.fi-ta-row`, not `.fi-ta-record`.** Filament has two row layouts: the ordinary table
  >    layout renders `<tr class="fi-ta-row">` (`index.blade.php:2221`) and the content/collapsible layout
  >    renders `.fi-ta-record` (`:1228`). Every table in this application uses the first, so the selector
  >    §1.2 specifies matched **nothing** and the feature was silently inert. The premise check in Phase 0
  >    verified `.fi-ta-record` *exists* — it does, in the other branch, a thousand lines away. The
  >    selector now covers both.
  > 2. **The row's URL is on the column cells, not on one row anchor.** In table layout each cell is its
  >    own `<a class="fi-ta-col">` carrying the record URL (`:2344-2348`); `fi-ta-record-content` is the
  >    content layout's single anchor. A selector for the latter alone would have shipped a menu with no
  >    link items — the one part of this feature the opening section calls non-negotiable.
  >
  > **And a finding that makes Phase 2 cheap rather than hard.** A third of the files declaring
  > `recordActions()` also use `ActionGroup::make`, and `ActionGroup::toEmbeddedHtml()` writes its items
  > **eagerly** into an `x-cloak` panel that is present in the DOM, already `isHidden()`-filtered for that
  > record (`vendor/filament/actions/src/ActionGroup.php:494-560`). So grouped actions reach the menu with
  > no dropdown opened and no second visibility decision. `extraRecordLinkAttributes()` also lands on the
  > column anchors when the cell uses the record's URL (`:2347`), so Phase 2's `data-record-key` will
  > arrive where the script looks for it.
  >
  > Three deliberate departures from the plan as written:
  >
  > - **Plain JS, not an Alpine component.** §1.1 requires one *delegated* listener and §3 requires no
  >   per-table code; an Alpine component needs an element to hold `x-data`, which means touching 147
  >   table files. Nothing here wants reactivity — the menu is built from the DOM on open and discarded on
  >   close.
  > - **`navigateOnce()` rather than "mark each container".** §2 proposed an idempotent per-container bind.
  >   With a document-delegated listener there is nothing to re-bind when new tables arrive, so the script
  >   is registered to execute once per real page load and the only navigation work left is closing the
  >   menu. Simpler, and one fewer piece of state to get wrong.
  > - **"Open" clicks the row's own anchor instead of copying its `href`.** Those anchors carry
  >   `x-on:click` with `Alpine.navigate`; a fresh anchor of ours would look identical and do a full page
  >   load. *Open in new tab* stays a real `<a target="_blank">`, because a new tab is a fresh document
  >   and the browser's own modifiers must keep working on the item itself.
  >
  > **The browser proof is repeatable, and needs no server, session or database.**
  > `table-context-menu:smoke` renders a real table through the one PHPUnit method that can
  > (`Livewire::test()` throws *"Invalid Livewire snapshot structure"* outside PHPUnit), wraps it in a
  > harness, and hands it to Chrome. It asserts: the menu opens and suppresses the native one; the link
  > items are present and the new-tab item is a genuine anchor; Escape closes; **Shift yields the native
  > menu**; right-clicking off a row yields the native menu; two rows differ; exactly one menu element
  > exists however many times it opens; `livewire:navigated` closes it; and no page errors.
  >
  > **Three of this phase's own bugs were caught by tests that would otherwise have passed vacuously**,
  > which is worth recording because the shape recurs. A guard globbing a directory that does not exist
  > asserted nothing and PHPUnit called it *risky* — the only reason it was noticed. A "no per-row cost"
  > test passed against a table with **no rows at all**, because the invoices table calls `deferLoading()`
  > and the first render is empty; it now asserts a floor of rendered rows first. And the smoke script's
  > own outside-the-row assertion was negated the wrong way and reported a pass as a failure. Each is now
  > either floored or commented at the point of the mistake.
- **Phase 2 — Every table.** `Table::configureUsing()` per §3, the `merge: true` care, and the sweep test.
  No table file is edited.

  > **Phase 2 built 2026-08-23.** — `Table::configureUsing()` in `AppServiceProvider::boot()`, four tests
  > in `TableContextMenuTest`, and two more assertions in the browser smoke run. **No table file was
  > edited**, which was the whole point: one registration, 147 table classes, and the record key now
  > reaches every row in both panels.
  >
  > **Both halves of the feature moved to `AppServiceProvider`, and that is a correction to §2's
  > wording.** §2 said "registered through `FilamentAsset::register()` in the panel provider", and Phase 1
  > did that — but `Table::configureUsing()` is a *global* Filament configuration, so registering it in
  > `AdminPanelProvider` would put a global effect in a file that names one panel. `FilamentAsset` is
  > global in the same way: the registry is a singleton and Filament renders its scripts in any panel's
  > layout, so the asset was never panel-scoped in effect, only in appearance. Both now sit together in
  > `AppServiceProvider::boot()`.
  >
  > That was worth checking rather than assuming, because going global only works if the styling does:
  > `PlatformPanelProvider` uses **the same** `resources/css/filament/admin/theme.css`, so the platform
  > panel gets a styled menu rather than an unstyled box.
  >
  > Three decisions:
  >
  > - **The sweep test is a source scan, not a Livewire walk over 147 resources**, and the trade is worth
  >   stating. The key is applied by one global registration, so the only way a table can lose it is by
  >   declaring `extraRecordLinkAttributes()` of its own — which a scan finds exactly and instantly.
  >   Booting 147 Livewire components to observe a property that is structurally guaranteed would add
  >   well over a minute to the suite and catch nothing the scan does not.
  > - **The `merge: true` guard is inverted into a tripwire**, which is the useful version of what §5.2
  >   asked for. §5.2 wanted a merge regression "the moment a table sets a link attribute of its own" and
  >   noted that today none does, so it would be vacuous. So instead the test asserts that **none does**:
  >   it passes today for a real reason, and the day a table declares link attributes it fails and names
  >   the merge question to whoever wrote them. Given that three of Phase 1's own tests were caught
  >   passing vacuously, writing the vacuous one anyway would have been the wrong lesson to take.
  > - **The attribute and the `wire:key` are asserted to *agree*, not merely to exist** — in PHP and again
  >   in the browser. The script prefers the attribute and parses `wire:key` when there is none, so if the
  >   two ever disagreed the row identified would depend on which route ran, and the bug would surface
  >   only on tables lacking the attribute. Agreement is what makes one fallback safe rather than a second
  >   source of truth.
  >
  > **The fallback is not dead code, and there is now a test that says so.** The Activity Log list calls
  > `recordUrl(null)` deliberately, so it renders no row anchor — and `extraRecordLinkAttributes()` only
  > ever reaches an anchor (`index.blade.php:1276` and `:2347`). That table therefore has no
  > `data-record-key` at all and is identified by `wire:key` alone. It is the one branch of the script
  > that every table anybody looks at would leave unexercised, which is exactly the kind that rots.
  >
  > One latent trap closed while here: the closure hints `Model|array`, matching Filament's own
  > `getExtraRecordLinkAttributes()` signature rather than the narrower `Model`. Nothing in this
  > application is array-backed today — nothing calls `->records()` — but this closure runs for every
  > table in every panel, so a narrow hint would be a TypeError thrown from a global provider and
  > surfacing as a broken page nowhere near its cause.
  >
  > **And one live defect, caught by comparing the committed tree against itself.** `filament:assets`
  > copies `resources/js/` into `public/js/app/`, that copy is tracked here alongside Filament's own, and
  > it had gone stale: Phase 1 published once, then fixed the row selector twice, and the committed public
  > copy was the version whose selector matched **nothing**. Every PHP test passed and the browser smoke
  > run passed, because all of them read `resources/js/` — while the panel serves `public/js/`. The
  > feature would have shipped inert.
  >
  > The test that should have caught it asserted the published file *existed*, which is a hair away from
  > asserting nothing. It now asserts the two are **byte for byte identical**, with the remedy in the
  > failure message. That is the fourth vacuous-adjacent assertion this feature has produced, and the
  > pattern is consistent enough to name: **every test that checks a thing is present should also check
  > that the thing is right**, because "present" is what passes when the work is half done.
- **Phase 3 — Selection and bulk.** §1.4, including the clear-selection-on-outside-right-click rule and
  the count in the label.

  > **Phase 3 built 2026-08-23.** — selection and bulk in `resources/js/table-context-menu.js`, five tests
  > in `TableContextMenuTest`, an Alpine selection stub in the smoke harness, and eight browser
  > assertions. Two rows selected on the invoices table now yields *Delete 2 selected / Issue 2 selected /
  > Record Payment 2 selected / Void 2 selected*; right-clicking a third row abandons the selection and
  > shows that row's own menu.
  >
  > Four decisions:
  >
  > - **A bulk action is identified by its mount context, not by a class.** Filament writes
  >   `mountAction('delete', {}, {"table":true,"bulk":true})`, and the toolbar it shares also holds the
  >   reorder trigger, the grouping selector, the column manager and any non-bulk toolbar action — none of
  >   which carries that context. A class-based guess would need revisiting every time Filament restyled
  >   one of them. The `\u0022` in the attribute is literal (Blade's `Js::from()`), not a browser escape,
  >   so the script normalises before testing and a test asserts the marker is really in the rendered HTML.
  > - **The count comes from `getSelectedRecordsCount()`, never from `selectedRecords.size`.** Filament
  >   supports selecting every record across every page, and in that mode it tracks **de**selections
  >   instead (`isTrackingDeselectedRecords`) — so counting the set ourselves would report a handful where
  >   the user had selected four thousand, and the label would lie about what the action is about to touch.
  > - **Bulk actions are already in the DOM before anything is selected.** The toolbar renders them
  >   unconditionally and only the *container* is `x-show`n on the count (`index.blade.php:347`,
  >   `:362-364`) — the same eager-render property that made a row's `ActionGroup` readable in Phase 1. No
  >   dropdown is opened and no second visibility decision is made.
  > - **Clearing the selection on an outside right-click is load-bearing, not tidiness.** A stale selection
  >   means the checkboxes still say six while the menu said one, and the next bulk action reached for from
  >   the *toolbar* operates on a selection the user thought they had abandoned.
  >
  > **The browser run found a bug no amount of reading would have: "Delete selected 2 selected".**
  > Filament's own bulk labels already end in the word — `DeleteBulkAction` is *Delete selected*, and so
  > are force-delete and restore — so appending the count naively stutters. Dropping that trailing word
  > first gives §1.4's own example exactly: *Delete 2 selected*. The trade is stated in the code: a custom
  > label genuinely ending in "selected" would read *"Mark as 2 selected"*, which nothing here has, and the
  > alternative is every default Filament bulk action reading like a stutter.
  >
  > **The one compromise, named.** The smoke harness is a `file://` page with no Alpine, Livewire or
  > server, so the selection state is a **stub** — what the browser proves is the script's *branching*
  > (selection wins, outside clears, the count reaches the label), not Filament's selection tracking. The
  > contract that stub stands in for is pinned separately and for real: a test reads Filament's shipped
  > `dist/index.js` and fails if `isRecordSelected`, `getSelectedRecordsCount` or `deselectAllRecords` is
  > renamed. Without that pairing a Filament upgrade could rename one and leave a stubbed test passing over
  > a feature that had quietly stopped reading the selection.
  >
  > A browser assertion also pins that **the unselected case is byte-identical to Phase 1's** — a phase
  > that adds a branch should be provably invisible when the branch is not taken.
  >
  > Phase 2's staleness guard earned itself immediately: editing the script for this phase failed
  > `test_the_published_script_is_the_source` on the next run, exactly as intended.
- **Phase 4 — Keyboard, touch, and the preference.** §4 plus the per-user off switch. The switch belongs
  with whatever holds user preferences at that point — if Phase 7 of `docs/reports-expansion-plan.md`
  (dashboard layouts) has landed, its per-user store is the obvious home rather than a second one.

  > **Phase 4 built 2026-08-23.** — the `ContextMenu` key and `Shift+F10`, focus return, an `aria-label`
  > naming the record, Home/End, touch long-press with its movement threshold, the off switch in the menu
  > and in the user menu, seven tests, and fourteen more browser assertions.
  >
  > **The off switch is `localStorage`, and the plan's own instruction is why.** It wants the preference to
  > live "with whatever holds user preferences at that point", naming Phase 7 of
  > `docs/reports-expansion-plan.md` as the obvious home "rather than a second one". **That phase has not
  > landed** — only its Phases 0.5 and 1.1 have, there is no `dashboard_layouts` table and no per-user
  > store anywhere — so building one here would create exactly the second store the plan warns against,
  > which Phase 7 would then have to reconcile with. `localStorage` is not that: it is client state for a
  > client gesture, which is where this application already keeps the domain rail's open state, and
  > per-device is arguably the better answer anyway — somebody who wants the menu off on a shop tablet may
  > well want it on at a desk. Raw, not Alpine's `$persist`, because `$persist` JSON-encodes and the script
  > reads with `getItem`; the rail partial documents that same seam.
  >
  > **And there is a route back**, which is the part easy to leave out. The menu carries the toggle because
  > that is where somebody annoyed by it will look — but a menu that has just switched itself off cannot
  > switch itself back on, so the user menu carries it too, through `USER_MENU_AFTER`. That partial uses
  > Alpine rather than a script tag: §2's concern is body scripts stacking `addEventListener` on every
  > `wire:navigate`, and `x-on:click` stacks nothing.
  >
  > **The browser run found a real flaw in my own cancel logic.** The first draft cancelled a pending
  > long-press on the window `scroll` event — which reads as obvious care and is wrong: `closeMenu()`
  > returns focus to the row, **focusing an element scrolls it into view**, and that scroll lands *after* a
  > press which started in the meantime and cancels it. The test caught it by pressing Escape and then
  > long-pressing, an order no one would think to check by hand. The finger's own events are the reliable
  > signals and were already handled — `pointermove` past the slop, and `pointercancel` at the moment
  > Chrome takes the gesture over for scrolling — so the scroll listener now only closes an open menu.
  >
  > Four smaller decisions:
  >
  > - **The keyboard and touch routes anchor to the row, not to a point.** §4 asks for touch to open
  >   "centred rather than at the pointer", and the same is true of the `ContextMenu` key: a keyboard user
  >   has no pointer, so a menu at the last mouse position could open off-screen entirely.
  > - **Focus return is captured, not assumed.** The menu can be opened from a cell, a checkbox or an
  >   action button, so `closeMenu()` restores whatever had focus — and only if it is still connected, since
  >   a menu closed by `livewire:navigated` is closing over a row that has just been replaced.
  > - **The `aria-label` borrows the row's first cell**, truncated. That is what a sighted user would call
  >   the row too; without it a screen reader announces "menu" and then a list of verbs belonging to
  >   whichever row the user happened to be on.
  > - **Enter and Space need no code.** The items are real `<button>` and `<a>` elements, so a focused one
  >   activates natively — which is the reason `buildItem` builds elements rather than divs with handlers.
  >
  > A note on the vocabulary §4 asks to share with the command palette: the labels here are Filament's own
  > action labels, read from the row, so they already match whatever the palette shows for the same
  > operations. Nothing to align, which is the benefit of deriving the menu rather than declaring it.
  >
  > **A third instance of one testing mistake, now worth stating as a rule.** An assertion that a string is
  > *absent* from a file will fail on the comment explaining why it is absent — it happened with
  > `window.open`, with a regex matched by another regex, and here with `$persist`. **Absence assertions
  > must target the usage, not the word**: `window.open(`, `$persist(`. Alongside Phase 2's rule — every
  > test that checks a thing is present should also check that it is right — that is two lessons this
  > feature has produced about tests rather than about code.
- **Phase 5 — Polish.** Grouped sections and separators mirroring `ActionGroup`s, colours for destructive
  items, and a "Copy" section (link, id, the row's primary label) which is the item people ask for once
  the menu exists.

## Risks

- **Taking away "open in new tab".** The most likely complaint and the one the whole first section is
  about: three link items in the menu, `Shift` for the native menu, middle-click untouched, and an off
  switch. If any of those four is skipped, expect the feature to be resented by the people who use lists
  hardest.
- **A menu that lists an action the user cannot run.** Only possible if somebody introduces a declared
  list (the package's model, or a "richer" menu later). §1.3 and the test in §5.1 exist to make that a
  loud failure. Any change that builds the menu from configuration rather than from the row should be
  reviewed as a permissions change.
- **Listener stacking under SPA navigation.** The exact trap `docs/page-load-performance-plan.md`
  documents. Mitigated by the asset delivery of §2, an idempotent bind, and the puppeteer test that
  navigates ten times and counts.
- **A menu floating over swapped content.** Livewire replaces the body on navigate; a menu that does not
  close on `livewire:navigated` ends up describing a row that is no longer on screen — and its items
  would still mount actions against the old component. Close on navigate, always.
- **Positioning inside scroll containers.** Tables live in relation managers, modals and the Reports
  pane, which is `overflow: clip` for reasons already documented in the theme. A fixed, viewport-anchored
  menu is the answer that works in all three; an absolutely positioned one inside the table will be
  clipped in at least one of them.
- **Long-press fighting scroll on tablets.** A shop or warehouse tablet scrolls a long list constantly.
  The movement threshold is not optional, and if it cannot be made reliable, ship without long-press
  rather than with a menu that opens when somebody tries to scroll.
- **A package's release cadence.** If Phase 0 chooses the package, this feature is now on somebody else's
  upgrade schedule for a UI convenience — cheap while it tracks Filament, and a blocker on the day it does
  not. Recording *why* the choice was made (here) is what makes reversing it a decision rather than an
  archaeology exercise.

## Suggested first slice

**Phase 0, then Phases 1–2.** Right-click working on every table with no per-table code, the three link
items intact, and `Shift` still giving the native menu. That is the whole of the request's value; bulk
selection (Phase 3) and the keyboard/touch routes (Phase 4) are what make it a feature rather than a
demo, and neither is large.
