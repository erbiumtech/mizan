# Right-Click Context Menu in Tables — Plan

**Status:** Proposed
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
- **Phase 1 — One table, built.** The Alpine component, the asset registration of §2, the delegated
  listener, menu from `.fi-ta-actions`, link items, positioning and dismissal — proven on the invoices
  table, which has the richest action set in the application.
- **Phase 2 — Every table.** `Table::configureUsing()` per §3, the `merge: true` care, and the sweep test.
  No table file is edited.
- **Phase 3 — Selection and bulk.** §1.4, including the clear-selection-on-outside-right-click rule and
  the count in the label.
- **Phase 4 — Keyboard, touch, and the preference.** §4 plus the per-user off switch. The switch belongs
  with whatever holds user preferences at that point — if Phase 7 of `docs/reports-expansion-plan.md`
  (dashboard layouts) has landed, its per-user store is the obvious home rather than a second one.
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
