/**
 * Right-click a table row, get that row's own actions — docs/table-context-menu-plan.md §1.
 *
 * The whole design rests on one finding, re-verified against filament/filament v5.7.3:
 * **Filament already computes and renders each row's authorized action set.** The row loop resolves
 * `$recordActionsByRecordKey[$recordKey] ?? $reduceVisibleRecordActions($record)`, and that reducer
 * clones each action, binds the record, and drops anything `isHidden()`
 * (`vendor/filament/tables/resources/views/index.blade.php:149-169`). An `ActionGroup`'s items are
 * filtered the same way and rendered **eagerly** into a panel that is present-but-cloaked
 * (`vendor/filament/actions/src/ActionGroup.php:494-560`).
 *
 * So this menu is a *view* of what the row already rendered, never a second list of actions. That one
 * decision removes the feature's main hazard — a menu offering an action the user may not run — because
 * there is no second authorization path to keep in step. It is also why no table file is edited to gain
 * the menu.
 *
 * **Plain JS rather than an Alpine component**, which is a deliberate departure from the plan's §2
 * wording. §1.1 requires one delegated listener rather than per-row or per-table binding, and an Alpine
 * component needs an element to attach `x-data` to — which would mean touching all 147 table files, the
 * exact cost this feature exists to avoid. Nothing here needs Alpine's reactivity: the menu is built on
 * open from the DOM and thrown away on close.
 *
 * **Failing open is the rule.** Every branch that cannot answer a question with certainty returns and
 * lets the browser's own menu appear. A menu that cannot identify its row must not eat the gesture.
 */
(() => {
    'use strict'

    /**
     * The row Filament renders, and the container holding its actions.
     *
     * **Two classes, because Filament has two row layouts**, and the plan's §1.2 named only one of them.
     * `.fi-ta-row` is the ordinary table layout — a `<tr>` per record (`index.blade.php:2221`) — and
     * `.fi-ta-record` is the content / collapsible layout (`:1228`). Every table in this application
     * currently renders the first, so a selector that knew only `.fi-ta-record` matched nothing at all
     * and the feature was silently inert. That was found by running the script against the invoices
     * table's real markup in a browser rather than by reading the view, which is worth remembering: both
     * classes exist in the file, a few hundred lines apart, in branches that look alike.
     */
    const ROW = '.fi-ta-row, .fi-ta-record'
    const ACTIONS = '.fi-ta-actions'

    /**
     * The table root — the element carrying `x-data="filamentTable(...)"` (`index.blade.php:208-212`).
     *
     * Both the rows and the header toolbar live inside it, which is what makes the bulk lookup safe when a
     * page holds several tables: a relation manager below a resource table, or two in one modal. Scoping
     * to the row's own root rather than to the document is the difference between acting on the selection
     * the user can see and acting on somebody else's.
     */
    const TABLE = '.fi-ta'
    const TOOLBAR = '.fi-ta-header-toolbar'

    /**
     * Filament's own dropdown trigger, which we must *not* treat as an action.
     *
     * An `ActionGroup` renders a `.fi-dropdown-trigger` whose job is "open the menu". Offering it inside
     * our menu would be an item that opens a second menu for the items we already listed.
     */
    const DROPDOWN_TRIGGER = '.fi-dropdown-trigger'

    /**
     * The off switch — §4, and it is raw `localStorage` on purpose.
     *
     * `docs/table-context-menu-plan.md` Phase 4 wants the preference to live "with whatever holds user
     * preferences at that point", and names Phase 7 of `docs/reports-expansion-plan.md` as the obvious
     * home "rather than a second one". That phase has not landed and there is no per-user store, so
     * building one here would create exactly the second store the plan warns against — and Phase 7 would
     * then have to reconcile with it.
     *
     * `localStorage` is not that second store. It is client state for a *client gesture*, which is where
     * this codebase already keeps the domain rail's open state, and per-device is arguably the better
     * answer anyway: somebody who wants the menu off on a shop tablet may well want it on at a desk.
     *
     * **Raw, not Alpine's `$persist`.** `$persist` JSON-encodes, so a boolean written by Alpine reads back
     * as the string `"false"` to anything using `getItem` directly — the rail partial already documents
     * that seam. The script and the user-menu toggle both use this key and the literal `'off'`, so there
     * is no encoding to disagree about.
     */
    const DISABLED_KEY = 'tableContextMenuDisabled'

    /**
     * Read at the moment of the gesture, never cached.
     *
     * So a toggle in another tab — or in the user menu on this page — takes effect on the next right-click
     * rather than on the next full page load.
     */
    const isTurnedOff = () => {
        try {
            return localStorage.getItem(DISABLED_KEY) === 'off'
        } catch {
            // Private browsing and some embedded webviews throw on access. A menu that cannot read the
            // preference should be on, because on is the state everything else here assumes.
            return false
        }
    }

    const turnOff = () => {
        try {
            localStorage.setItem(DISABLED_KEY, 'off')
        } catch {
            // Nothing to do: the item is a courtesy and the native menu is one Shift away regardless.
        }
    }

    /** How long a touch has to rest before it counts as a long-press — §4. */
    const LONG_PRESS_MS = 500

    /**
     * How far a touch may wander and still count.
     *
     * Not optional, and the plan says why: "a shop or warehouse tablet scrolls a long list constantly", so
     * a long-press with no movement threshold opens a menu every time somebody tries to scroll. Ten pixels
     * is enough to absorb the jitter of a finger resting without swallowing a deliberate flick.
     */
    const LONG_PRESS_SLOP = 10

    let menu = null
    let bound = false

    /** What had focus when the menu opened, so Escape can give it back — §4. */
    let returnFocusTo = null

    /** The pending long-press, so a move or a lift can cancel it. */
    let longPress = null

    // ---------------------------------------------------------------- resolving the row

    /**
     * The record key for a row.
     *
     * `data-record-key` is ours, applied centrally in Phase 2 through `extraRecordLinkAttributes()`.
     * Until then — and for any table that somehow lacks it — fall back to parsing Filament's
     * `wire:key`, whose shape is `{componentId}.table.records.{recordKey}` (`index.blade.php:1222`).
     * That is Filament's internal shape, which is exactly why it is the fallback and not the primary.
     */
    const recordKeyOf = (row) => {
        const own = row.querySelector('[data-record-key]')?.dataset.recordKey

        if (own) {
            return own
        }

        const wireKey = row.getAttribute('wire:key') ?? ''
        const marker = '.table.records.'
        const at = wireKey.indexOf(marker)

        return at === -1 ? null : wireKey.slice(at + marker.length) || null
    }

    /**
     * The Livewire component that owns the row, so a menu item can reach the right `$wire`.
     *
     * Resolved by walking up to the nearest element Livewire has initialised. Read at open time rather
     * than cached: a table inside a modal or a relation manager belongs to a different component from
     * the page's own table, and there can be several on one screen.
     */
    const componentOf = (row) => row.closest('[wire\\:id]')

    // ---------------------------------------------------------------- reading the row's actions

    /**
     * Every action the row has already rendered, in the order it rendered them.
     *
     * Includes the items inside a cloaked `ActionGroup` panel, which are in the DOM and already
     * `isHidden()`-filtered for this record — so a grouped action reaches the menu without the group
     * ever being opened and without a second visibility decision.
     *
     * The trigger itself is excluded (see DROPDOWN_TRIGGER), and so is anything disabled: Filament
     * disables an action it will refuse to mount, and an item that does nothing is worse than an item
     * that is absent, because nothing explains it.
     */
    const actionsIn = (row) => {
        const container = row.querySelector(ACTIONS)

        if (!container) {
            return []
        }

        return Array.from(container.querySelectorAll('a, button'))
            .filter((el) => !el.closest(DROPDOWN_TRIGGER))
            .filter((el) => !el.disabled && el.getAttribute('aria-disabled') !== 'true')
            .map((el) => ({
                element: el,
                label: labelOf(el),
                icon: iconOf(el),
                danger: isDanger(el),
                group: groupOf(el),
            }))
            .filter((item) => item.label !== '')
    }

    /**
     * What to call the item.
     *
     * Filament renders an icon-only action as a button whose accessible name is its `aria-label` or a
     * tooltip, so the visible text is often empty — taking `textContent` alone would produce a menu of
     * blank rows on exactly the tables that use icon buttons.
     */
    const labelOf = (el) => {
        const text = el.textContent?.replace(/\s+/g, ' ').trim()

        return (
            text ||
            el.getAttribute('aria-label')?.trim() ||
            el.getAttribute('title')?.trim() ||
            el.dataset.tooltip?.trim() ||
            ''
        )
    }

    /** The action's own icon, cloned so the menu looks like the row it came from. */
    const iconOf = (el) => {
        const svg = el.querySelector('svg')

        if (!svg) {
            return null
        }

        const clone = svg.cloneNode(true)
        clone.removeAttribute('class')
        clone.classList.add('fi-ta-context-menu-icon')

        return clone
    }

    /**
     * Whether to colour the item as destructive.
     *
     * Read from the classes Filament already put on the element for its own colour, rather than from a
     * list of action names kept here — a list would go stale silently the first time somebody added a
     * destructive action, and the failure would be a delete that does not look like one.
     */
    const isDanger = (el) => /(^|\s|-)fi-color-danger(\s|$|-)/.test(el.className)

    /**
     * The `ActionGroup` an action belongs to, by name — §5's grouped sections.
     *
     * An `ActionGroup` renders a `.fi-dropdown` holding a `.fi-dropdown-trigger` and a cloaked
     * `.fi-dropdown-panel` (`vendor/filament/actions/src/ActionGroup.php:533-560`). An action inside that
     * panel belongs to the group; anything else is a direct action on the row. The name comes from the
     * trigger, which is what the row itself labels the group with — so the menu mirrors the row rather than
     * inventing its own headings.
     *
     * Null for an unlabelled group, which is the common icon-only "⋯" trigger: a heading reading *Actions*
     * or nothing at all is noise, and the separator alone is the honest way to show the boundary.
     */
    const groupOf = (el) => {
        const dropdown = el.closest('.fi-dropdown')

        if (!dropdown || !el.closest('.fi-dropdown-panel')) {
            return null
        }

        const label = labelOf(dropdown.querySelector(DROPDOWN_TRIGGER) ?? dropdown)

        return label && label.length <= 24 ? label : null
    }

    // ---------------------------------------------------------------- selection and bulk

    /**
     * Filament's own selection state, through Alpine — §1.4.
     *
     * `filamentTable` exposes `isRecordSelected(key)`, `getSelectedRecordsCount()` and
     * `deselectAllRecords()`, and `Alpine.$data(row)` reaches them by walking up to that scope. Using
     * Filament's own count rather than counting a `Set` ourselves matters: it also answers correctly in
     * the *select-all-across-pages* mode, where Filament tracks **de**selections instead
     * (`isTrackingDeselectedRecords`) and a naive `selectedRecords.size` would report a handful when the
     * user has selected four thousand.
     *
     * Returns null when Alpine is not there, and the caller then treats the row as unselected. That is
     * not a hypothetical branch: it is what the file:// harness the smoke test drives looks like, and it
     * is the right degradation — a row menu is useful, and a bulk menu built on a selection we cannot
     * read would act on the wrong records.
     */
    const selectionOf = (row) => {
        const scope = window.Alpine?.$data?.(row)

        if (!scope || typeof scope.isRecordSelected !== 'function') {
            return null
        }

        return {
            isSelected: (key) => scope.isRecordSelected(key),
            count: () => Number(scope.getSelectedRecordsCount?.() ?? 0),
            clear: () => scope.deselectAllRecords?.(),
        }
    }

    /**
     * An action's click expression, with Blade's escaping undone.
     *
     * Filament writes the handler through `Js::from()`, so the attribute reads
     * `mountAction('delete', {}, JSON.parse('{\u0022table\u0022:true,\u0022bulk\u0022:true}'))` — the
     * `\u0022` are literal characters in the HTML, not a browser escape, so `getAttribute` hands them
     * back as-is. Normalising them to quotes is what lets one honest string test work on both shapes.
     */
    const handlerOf = (el) =>
        (el.getAttribute('x-on:click') ?? el.getAttribute('wire:click') ?? '').replaceAll('\\u0022', '"')

    /**
     * Whether an element is a **bulk** action.
     *
     * Read from the mount context Filament put in the handler — `{"table":true,"bulk":true}` — rather than
     * from a class or a position in the toolbar. The toolbar also holds the reorder trigger, the grouping
     * selector, the column manager, the filters trigger and any non-bulk toolbar action, and none of those
     * carries that context. A class-based guess would have to be revisited every time Filament restyled
     * one of them.
     */
    const isBulkAction = (el) => /"bulk"\s*:\s*true/.test(handlerOf(el))

    /**
     * A bulk label with the count in it, reading like English.
     *
     * **Filament's own bulk labels already end in "selected"** — `DeleteBulkAction` is *Delete selected*,
     * and so are the force-delete and restore ones. Appending naively produced *"Delete selected 2
     * selected"*, which the first browser run showed and which no amount of reading the plan would have.
     * Dropping that trailing word first gives §1.4's own example exactly: *Delete 2 selected*, and
     * *Issue 2 selected* for a label that never had it.
     *
     * The trade, stated: a custom label genuinely ending in the word — *"Mark as selected"* — becomes
     * *"Mark as 2 selected"*. Clumsy, and nothing in this application has one; the alternative is leaving
     * every default Filament bulk action reading like a stutter.
     */
    const withCount = (label, count) =>
        `${label.replace(/\s+selected$/i, '')} ${count} selected`.trim()

    /**
     * The bulk actions for the whole selection, labelled with its size — §1.4's "Delete 6 selected".
     *
     * They are already in the DOM: the toolbar renders them unconditionally and only the *container* is
     * `x-show`n on the selection count (`index.blade.php:347`, `:362-364`), so this reads them without
     * opening the group — the same eager-render property that makes a row's `ActionGroup` readable.
     *
     * The count is in every label rather than in a heading, because a menu item that says *Delete* when
     * six things are selected is how somebody deletes five more than they meant to.
     */
    const bulkActionsIn = (row, count) => {
        const toolbar = row.closest(TABLE)?.querySelector(TOOLBAR)

        if (!toolbar) {
            return []
        }

        return Array.from(toolbar.querySelectorAll('button, a'))
            .filter((el) => isBulkAction(el))
            .filter((el) => !el.disabled && el.getAttribute('aria-disabled') !== 'true')
            .map((el) => ({
                element: el,
                label: withCount(labelOf(el), count),
                icon: iconOf(el),
                danger: isDanger(el),
            }))
            .filter((item) => item.label.trim() !== `${count} selected`)
    }

    // ---------------------------------------------------------------- the link items

    /**
     * Open / Open in new tab / Copy link — docs/table-context-menu-plan.md's opening section.
     *
     * Suppressing the native menu takes away *open in new tab*, and on a list of invoices that is the
     * fastest way somebody works. So the menu carries it back, and **as a real anchor** rather than a
     * `window.open`, so the browser's own modifiers and middle-click keep behaving.
     *
     * Absent rather than dead where the row has no URL — a `recordAction` row renders a `<button>`
     * instead (`index.blade.php:1288-1298`), as the Activity Log list deliberately does. The menu
     * reflects the row's actual mode.
     */
    const linkItemsFor = (row) => {
        /*
         * Two row shapes, both real, and a selector that only knew one would silently ship a menu with
         * no link items on most tables.
         *
         *  - **Table layout** — every column cell is its own `<a class="fi-ta-col">` carrying the
         *    record's URL (`index.blade.php:2344-2348`). Nine of them on the invoices table.
         *  - **Content / collapsible layout** — one `<a class="fi-ta-record-content">` for the whole row
         *    (`:1275-1276`).
         *
         * Action anchors are excluded for free: an `EditAction` renders as `fi-ac-link-action` inside
         * `.fi-ta-actions` and carries neither class, so it reaches the menu as an *action* rather than
         * being mistaken for the row's own link.
         */
        const anchor = row.querySelector('a.fi-ta-record-content[href], a.fi-ta-col[href]')

        if (!anchor) {
            return []
        }

        const items = [
            /*
             * **Open clicks the row's own anchor rather than duplicating its href.**
             *
             * Those anchors carry `x-on:click` with `Alpine.navigate` — Filament's SPA navigation. A
             * fresh anchor of ours with the same `href` would look identical and do a full page load,
             * which is slower and loses the scroll position the rest of the panel keeps. Clicking the
             * original is the same rule the action items follow, and for the same reason.
             */
            { label: 'Open', element: anchor },

            /*
             * **New tab is a real anchor, and this is the one item that must not be a click proxy.**
             *
             * The whole complaint this feature invites is that right-click takes away *open in new tab*.
             * A genuine `<a target="_blank">` means the browser's own modifiers and middle-click keep
             * behaving on the menu item itself; a JS `open()` would not. A new tab is a fresh document
             * anyway, so there is no SPA navigation to preserve here.
             */
            { label: 'Open in new tab', href: anchor.href, newTab: true },
        ]

        return items
    }

    /**
     * The Copy section — §5, "the item people ask for once the menu exists".
     *
     * Three things worth copying off a row: its link, its id, and what it is called. The id is the one that
     * looks least useful and is asked for most — it is what somebody pastes into a support ticket, a SQL
     * console or a message to a colleague, and reading it off the URL bar means opening the record first.
     *
     * Only offered where the browser will actually do it. `navigator.clipboard` is undefined on insecure
     * origins, and an item that silently fails is worse than one that was never there.
     */
    const copyItemsFor = (row) => {
        if (!navigator.clipboard?.writeText) {
            return []
        }

        const items = []
        const anchor = row.querySelector('a.fi-ta-record-content[href], a.fi-ta-col[href]')

        if (anchor) {
            items.push({ label: 'Copy link', copy: anchor.href, muted: true })
        }

        const key = recordKeyOf(row)

        if (key) {
            items.push({ label: `Copy ID ${key}`, copy: key, muted: true })
        }

        // The row's own name, quoted so it is obvious which part is the record and which is the verb.
        const name = row.querySelector('.fi-ta-col, .fi-ta-record-content')
            ?.textContent?.replace(/\s+/g, ' ')
            .trim()
            .slice(0, 40)

        if (name) {
            items.push({ label: `Copy “${name}”`, copy: name, muted: true })
        }

        return items
    }

    // ---------------------------------------------------------------- the menu element

    /**
     * What to call this row out loud — §4's "a label naming the record".
     *
     * The first cell's text, which is what a person reading the screen would call the row too: an invoice
     * number, a job code, a name. Truncated because a description column can run to a paragraph, and a
     * menu announcing a paragraph before its first item is worse than one announcing nothing.
     *
     * Falls back to the plain label when there is no text to borrow — a table of icons, or a row whose
     * first cell is a checkbox.
     */
    const labelForRow = (row) => {
        const text = row.querySelector('.fi-ta-col, .fi-ta-record-content')
            ?.textContent?.replace(/\s+/g, ' ')
            .trim()
            .slice(0, 80)

        return text ? `Actions for ${text}` : 'Row actions'
    }

    /** One element for the page, created on first use — never one per row. */
    const ensureMenu = () => {
        if (menu?.isConnected) {
            return menu
        }

        menu = document.createElement('div')
        menu.className = 'fi-ta-context-menu'
        menu.setAttribute('role', 'menu')
        menu.hidden = true
        document.body.appendChild(menu)

        return menu
    }

    /**
     * Close, and give focus back to where it came from — §4.
     *
     * Returning focus is what makes the keyboard route usable rather than a trap: without it, Escape
     * leaves focus on a hidden element and the next Tab starts from the top of the document. It is also
     * why `returnFocusTo` is captured on open rather than assumed to be the row — the menu can be opened
     * from a cell, a checkbox, or an action button, and the courteous thing is to go back to whichever.
     */
    const closeMenu = () => {
        if (!menu || menu.hidden) {
            return
        }

        menu.hidden = true
        menu.replaceChildren()
        menu.removeAttribute('aria-label')

        const target = returnFocusTo
        returnFocusTo = null

        // Only if it is still on the page: a menu closed by `livewire:navigated` is closing over a row
        // that has just been replaced, and focusing a detached node throws focus to the body silently.
        if (target?.isConnected) {
            target.focus()
        }
    }

    /**
     * A boundary between sections, optionally named.
     *
     * A **labelled** separator is valid ARIA and is announced, which is how a group's name reaches a screen
     * reader without putting a non-`menuitem` element inside a `role="menu"`. The visible heading below is
     * `aria-hidden`, so the name is announced once rather than twice.
     */
    const separator = (label = null) => {
        const hr = document.createElement('div')
        hr.className = 'fi-ta-context-menu-separator'
        hr.setAttribute('role', 'separator')

        if (label) {
            hr.setAttribute('aria-label', label)
        }

        return hr
    }

    /** The group's name, for sighted readers. Announced by the separator above it, so hidden from AT. */
    const heading = (label) => {
        const el = document.createElement('div')
        el.className = 'fi-ta-context-menu-heading'
        el.setAttribute('aria-hidden', 'true')
        el.textContent = label

        return el
    }

    /** A menu row. Anchors stay anchors, so the browser keeps its own behaviour on them. */
    const buildItem = (item) => {
        const el = document.createElement(item.href ? 'a' : 'button')
        el.className = 'fi-ta-context-menu-item'
        el.setAttribute('role', 'menuitem')
        el.tabIndex = -1

        if (item.href) {
            el.href = item.href

            if (item.newTab) {
                el.target = '_blank'
                el.rel = 'noopener'
            }
        } else {
            el.type = 'button'
        }

        if (item.danger) {
            el.classList.add('fi-ta-context-menu-item-danger')
        }

        if (item.muted) {
            el.classList.add('fi-ta-context-menu-item-muted')
        }

        if (item.icon) {
            el.appendChild(item.icon)
        }

        const label = document.createElement('span')
        label.textContent = item.label
        el.appendChild(label)

        el.addEventListener('click', (event) => {
            if (item.copy) {
                event.preventDefault()
                navigator.clipboard.writeText(item.copy)
                closeMenu()

                return
            }

            if (item.href) {
                // Let the anchor be an anchor. Closing first so the menu is not left over a page that
                // is navigating away underneath it.
                closeMenu()

                return
            }

            if (item.onSelect) {
                event.preventDefault()
                item.onSelect()

                return
            }

            event.preventDefault()
            closeMenu()

            /*
             * Click the row's own element.
             *
             * Not a hand-rolled `$wire.mountAction(...)`: the element already carries Filament's own
             * `wire:click` with the right name, arguments and context, and dispatching its click is what
             * makes this a view of the row rather than a second implementation of it. A cloaked dropdown
             * item still receives a dispatched click — visibility does not gate listeners.
             */
            item.element.click()
        })

        return el
    }

    // ---------------------------------------------------------------- positioning

    /**
     * Fixed and anchored to the pointer, flipped rather than clipped.
     *
     * Fixed because tables live in relation managers, modals and the Reports pane, and that last one is
     * `overflow: clip` — an absolutely positioned menu inside the table would be cut off in at least one
     * of the three. Flipped rather than allowed to overflow because the menu must never be the thing
     * that makes the page scroll.
     */
    /**
     * Where the menu goes, which depends on what opened it — §4.
     *
     * A right-click has a point and the menu belongs at it. A keyboard press and a long-press do not: the
     * plan asks for touch to open "centred rather than at the pointer", and the same is true of the
     * `ContextMenu` key — anchoring to a stale pointer position would put the menu wherever the mouse
     * happened to be resting, which for a keyboard user could be off-screen entirely. Both anchor to the
     * row instead, which is the thing the gesture was actually about.
     */
    const placeMenu = (row, at) => {
        if (at) {
            place(at.x, at.y)

            return
        }

        const box = row.getBoundingClientRect()

        place(box.left + Math.min(box.width / 2, 240), box.bottom - 4)
    }

    const place = (x, y) => {
        // Measured while hidden but laid out, so the flip decision uses the real size.
        menu.style.visibility = 'hidden'
        menu.hidden = false

        const { width, height } = menu.getBoundingClientRect()
        const margin = 8

        let left = x
        let top = y

        if (left + width + margin > window.innerWidth) {
            left = Math.max(margin, x - width)
        }

        if (top + height + margin > window.innerHeight) {
            top = Math.max(margin, y - height)
        }

        menu.style.left = `${left}px`
        menu.style.top = `${top}px`
        menu.style.visibility = ''
    }

    // ---------------------------------------------------------------- opening

    /**
     * **Selection wins over the row** — §1.4.
     *
     * Right-clicking *inside* a selection acts on the selection; right-clicking *outside* one clears it
     * and acts on the row under the cursor. That is what every file manager does, and doing anything else
     * is how somebody deletes six records while looking at the one they right-clicked.
     *
     * The clearing is deliberate rather than incidental. Leaving a stale selection behind after showing a
     * single-row menu means the checkboxes still say six while the menu said one, and the next bulk action
     * the user reaches for — from the toolbar, not from here — operates on a selection they thought they
     * had abandoned.
     *
     * @return {{items: array, bulk: boolean}}
     */
    const menuContentsFor = (row) => {
        const selection = selectionOf(row)
        const count = selection?.count() ?? 0

        if (count > 0) {
            if (selection.isSelected(recordKeyOf(row))) {
                return {
                    sections: [{ label: null, items: bulkActionsIn(row, count) }],
                    bulk: true,
                }
            }

            selection.clear()
        }

        return { sections: rowSections(row), bulk: false }
    }

    /**
     * A row's menu, in sections — §5.
     *
     * Links, then the row's direct actions, then one section per `ActionGroup` **mirroring the row's own
     * grouping**, then Copy. Sections rather than one flat list because the row already draws these
     * distinctions and a menu that discarded them would be a longer list of the same things in a less
     * meaningful order.
     *
     * `null` is a plain separator; a string is a named one. Both are dropped by `openFor` when they would
     * lead or trail, so a row with no links or no groups produces no stray rules.
     */
    const rowSections = (row) => {
        const actions = actionsIn(row)
        const ungrouped = actions.filter((item) => item.group === null)

        // Group names in the order the row rendered them, so the menu reads top to bottom the same way.
        const groups = []

        for (const item of actions) {
            if (item.group !== null && !groups.includes(item.group)) {
                groups.push(item.group)
            }
        }

        const sections = [
            { label: null, items: linkItemsFor(row) },
            { label: null, items: ungrouped },
        ]

        for (const group of groups) {
            sections.push({ label: group, items: actions.filter((item) => item.group === group) })
        }

        sections.push({ label: null, items: copyItemsFor(row) })

        return sections
    }

    /**
     * Open the menu for a row.
     *
     * `at` is either a point (a right-click) or null (keyboard and touch), and the difference is §4's:
     * a menu anchored to where the pointer last was is wrong for a gesture that had no pointer, so those
     * open against the row itself.
     */
    const openFor = (row, at) => {
        const { sections, bulk } = menuContentsFor(row)

        // Empty sections vanish, which is what keeps a row with no links or no groups from producing stray
        // rules. Done here rather than at each builder so every branch gets it for free.
        const filled = sections.filter((section) => section.items.length > 0)

        // Nothing to offer. Fail open: the native menu is more useful than an empty box of ours.
        //
        // Reached in one real case worth naming — a selection whose bulk actions are all hidden by policy.
        // Filament renders none of them, so there is nothing to show, and the honest answer is to let the
        // browser have the gesture rather than open a menu with one item in it that says nothing.
        if (filled.length === 0) {
            return false
        }

        ensureMenu()
        menu.replaceChildren()
        menu.classList.toggle('fi-ta-context-menu-bulk', bulk)
        menu.setAttribute('aria-label', bulk ? 'Actions for the selected rows' : labelForRow(row))

        filled.forEach((section, index) => {
            // A separator *between* sections, never before the first — a rule at the top of a menu looks
            // like a rendering fault.
            if (index > 0) {
                menu.appendChild(separator(section.label))
            }

            if (section.label) {
                menu.appendChild(heading(section.label))
            }

            section.items.forEach((item) => menu.appendChild(buildItem(item)))
        })

        /*
         * The off switch, last and behind a rule — §4 and the Risks section.
         *
         * Here because this is where somebody is when the feature is annoying them, which is the only
         * moment they will look for it. It is not menu-*only*: the user menu carries the same toggle, and
         * has to, because a menu that has just switched itself off cannot switch itself back on.
         */
        menu.appendChild(separator())
        menu.appendChild(buildItem({
            label: 'Turn off right-click menus',
            muted: true,
            onSelect: () => {
                turnOff()
                closeMenu()
            },
        }))

        // Captured before focus moves into the menu, so Escape can put it back — see closeMenu().
        returnFocusTo = document.activeElement

        placeMenu(row, at)
        menu.querySelector('.fi-ta-context-menu-item')?.focus()

        return true
    }

    // ---------------------------------------------------------------- the one listener

    /**
     * The row a gesture is about, or null to leave the gesture alone.
     *
     * One place for the three checks every opener shares — off switch, a row, an identifiable row — so the
     * keyboard and touch routes cannot drift from the pointer one. **Failing open is the rule**: each
     * `null` here means the browser keeps its own behaviour.
     */
    const rowFor = (target) => {
        if (isTurnedOff()) {
            return null
        }

        const row = target?.closest?.(ROW)

        if (!row || !recordKeyOf(row) || !componentOf(row)) {
            return null
        }

        return row
    }

    const handleContextMenu = (event) => {
        /*
         * **The escape hatch, and it is two lines.**
         *
         * Firefox gives the native menu on Shift+right-click by itself; Chromium does not, so we check.
         * The plan calls this "the difference between a power feature and a complaint", and it is the
         * reason taking over this gesture is defensible at all.
         */
        if (event.shiftKey) {
            return
        }

        const row = rowFor(event.target)

        if (!row) {
            return
        }

        if (openFor(row, { x: event.clientX, y: event.clientY })) {
            event.preventDefault()
        }
    }

    /**
     * The platform's own context-menu keys — §4.
     *
     * `ContextMenu` is the dedicated key; `Shift+F10` is the binding every desktop platform also honours
     * and the one people who do not have that key use. Opening for the **focused** row rather than a
     * hovered one is the whole point: this is the route for somebody who is not using a mouse.
     *
     * No conflict with the Shift escape hatch, which is on `contextmenu` events rather than keystrokes.
     */
    const handleContextMenuKey = (event) => {
        const isContextMenuKey = event.key === 'ContextMenu' || (event.key === 'F10' && event.shiftKey)

        if (!isContextMenuKey || menu?.hidden === false) {
            return
        }

        const row = rowFor(document.activeElement)

        if (row && openFor(row, null)) {
            event.preventDefault()
        }
    }

    // ---------------------------------------------------------------- touch

    /**
     * Long-press on a touch screen — §4.
     *
     * Only for `pointerType === 'touch'`: a mouse already has a right button and a pen has a barrel
     * button, and putting a half-second delay in front of either would make both feel broken.
     *
     * **The movement threshold is not optional.** The plan is explicit that "a shop or warehouse tablet
     * scrolls a long list constantly", so a long-press that ignored movement would open a menu every time
     * somebody flicked the list — and it would open it *mid-scroll*, over whichever row had slid under the
     * finger. Cancel on wander, on lift, on cancel, and on scroll.
     */
    const handlePointerDown = (event) => {
        cancelLongPress()

        if (event.pointerType !== 'touch' || event.isPrimary === false) {
            return
        }

        const row = rowFor(event.target)

        if (!row) {
            return
        }

        longPress = {
            row,
            x: event.clientX,
            y: event.clientY,
            timer: window.setTimeout(() => {
                longPress = null
                openFor(row, null)
            }, LONG_PRESS_MS),
        }
    }

    const handlePointerMove = (event) => {
        if (!longPress) {
            return
        }

        const wandered =
            Math.abs(event.clientX - longPress.x) > LONG_PRESS_SLOP ||
            Math.abs(event.clientY - longPress.y) > LONG_PRESS_SLOP

        if (wandered) {
            cancelLongPress()
        }
    }

    const cancelLongPress = () => {
        if (longPress) {
            window.clearTimeout(longPress.timer)
            longPress = null
        }
    }

    const handleKeyDown = (event) => {
        if (menu?.hidden !== false) {
            return
        }

        if (event.key === 'Escape') {
            event.preventDefault()
            closeMenu()

            return
        }

        const navigation = ['ArrowDown', 'ArrowUp', 'Home', 'End']

        if (! navigation.includes(event.key)) {
            return
        }

        /*
         * Arrow, Home and End through the items.
         *
         * Enter and Space need nothing: the items are real `<button>` and `<a>` elements, so the browser
         * activates a focused one natively — which is also why `buildItem` builds elements rather than
         * divs with click handlers.
         */
        event.preventDefault()

        const items = Array.from(menu.querySelectorAll('.fi-ta-context-menu-item'))

        if (items.length === 0) {
            return
        }

        if (event.key === 'Home') {
            items[0].focus()

            return
        }

        if (event.key === 'End') {
            items[items.length - 1].focus()

            return
        }

        const at = items.indexOf(document.activeElement)
        const step = event.key === 'ArrowDown' ? 1 : -1

        items[(at + step + items.length) % items.length].focus()
    }

    const bind = () => {
        if (bound) {
            return
        }

        document.addEventListener('contextmenu', handleContextMenu)
        document.addEventListener('keydown', handleKeyDown)
        document.addEventListener('keydown', handleContextMenuKey)

        // Touch. `pointerup` and `pointercancel` cancel a pending press; scroll does too, below.
        document.addEventListener('pointerdown', handlePointerDown)
        document.addEventListener('pointermove', handlePointerMove)
        document.addEventListener('pointerup', cancelLongPress)
        document.addEventListener('pointercancel', cancelLongPress)

        // Any click elsewhere, including the one that chose an item — the item's own handler closes
        // first, so this is the "clicked outside" case.
        document.addEventListener('click', (event) => {
            if (menu && !menu.contains(event.target)) {
                closeMenu()
            }
        })

        /*
         * Scroll and resize move the row out from under a menu anchored to where the pointer was.
         *
         * **Deliberately does not cancel a pending long-press**, which the first draft did and which was
         * wrong. A `scroll` event is not evidence about the finger: focusing an element scrolls it into
         * view, so `closeMenu()` returning focus to a row emits one — and it lands *after* a press that
         * started in the meantime, cancelling it. That is not hypothetical; it is how the browser test
         * caught this, by pressing Escape and then long-pressing.
         *
         * The finger's own events are the reliable signal and are already handled: `pointermove` past the
         * slop while the user is dragging, and `pointercancel` at the moment Chrome takes the gesture over
         * for scrolling. Between them a real scroll always cancels the press, and a scroll nobody's finger
         * caused no longer does.
         */
        window.addEventListener('scroll', closeMenu, true)
        window.addEventListener('resize', closeMenu)

        /*
         * **Close on navigation, always.**
         *
         * Livewire replaces the body on `wire:navigate`. A menu left floating describes a row that is no
         * longer on screen, and its items hold element references into a detached tree — so clicking one
         * would mount an action against the old component. This is the bug this application would
         * produce in exactly one way, so it is closed in exactly one place.
         */
        document.addEventListener('livewire:navigated', () => {
            cancelLongPress()
            closeMenu()
        })

        bound = true
    }

    bind()
})()
