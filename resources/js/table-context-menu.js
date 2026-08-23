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
     * Filament's own dropdown trigger, which we must *not* treat as an action.
     *
     * An `ActionGroup` renders a `.fi-dropdown-trigger` whose job is "open the menu". Offering it inside
     * our menu would be an item that opens a second menu for the items we already listed.
     */
    const DROPDOWN_TRIGGER = '.fi-dropdown-trigger'

    let menu = null
    let bound = false

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

        // Only offered where the browser will actually do it. `navigator.clipboard` is undefined on
        // insecure origins, and an item that silently fails is worse than one that was never there.
        if (navigator.clipboard?.writeText) {
            items.push({ label: 'Copy link', copy: anchor.href })
        }

        return items
    }

    // ---------------------------------------------------------------- the menu element

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

    const closeMenu = () => {
        if (menu) {
            menu.hidden = true
            menu.replaceChildren()
        }
    }

    const separator = () => {
        const hr = document.createElement('div')
        hr.className = 'fi-ta-context-menu-separator'
        hr.setAttribute('role', 'separator')

        return hr
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

    const openFor = (row, x, y) => {
        const actions = actionsIn(row)
        const links = linkItemsFor(row)

        // Nothing to offer. Fail open: the native menu is more useful than an empty box of ours.
        if (actions.length === 0 && links.length === 0) {
            return false
        }

        ensureMenu()
        menu.replaceChildren()

        links.forEach((item) => menu.appendChild(buildItem(item)))

        if (links.length && actions.length) {
            menu.appendChild(separator())
        }

        actions.forEach((item) => menu.appendChild(buildItem(item)))

        place(x, y)
        menu.querySelector('.fi-ta-context-menu-item')?.focus()

        return true
    }

    // ---------------------------------------------------------------- the one listener

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

        const row = event.target.closest?.(ROW)

        if (!row) {
            return
        }

        // Identify the row or get out of the way. Resolved before anything is built so that a table we
        // cannot read leaves the gesture untouched rather than opening an empty menu.
        if (!recordKeyOf(row) || !componentOf(row)) {
            return
        }

        if (openFor(row, event.clientX, event.clientY)) {
            event.preventDefault()
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

        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return
        }

        // Arrow navigation now rather than in Phase 4, because a `role="menu"` that cannot be arrowed
        // through is a worse lie than no role at all. Opening *by keyboard* is still Phase 4.
        event.preventDefault()

        const items = Array.from(menu.querySelectorAll('.fi-ta-context-menu-item'))

        if (items.length === 0) {
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

        // Any click elsewhere, including the one that chose an item — the item's own handler closes
        // first, so this is the "clicked outside" case.
        document.addEventListener('click', (event) => {
            if (menu && !menu.contains(event.target)) {
                closeMenu()
            }
        })

        // Scroll and resize move the row out from under a menu anchored to where the pointer was.
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
        document.addEventListener('livewire:navigated', closeMenu)

        bound = true
    }

    bind()
})()
