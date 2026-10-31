{{--
    The right-click menu's switch, on the profile page — docs/table-context-menu-plan.md §4.

    **This is the route back.** The context menu carries the same toggle, because that is where somebody
    annoyed by it will look — but a menu that has just switched itself off cannot switch itself back on,
    so the preference needs a home that is always reachable. It used to be a line in the user menu; it is
    now a row on the person's own settings page (EditProfile), which the user menu always opens, drawn as
    Material's switch: on means the menus appear.

    Alpine rather than a script tag, and the distinction is §2's: the concern there is body scripts being
    re-executed on every `wire:navigate` and stacking `addEventListener` calls. `x-data` and `x-on:click`
    stack nothing — Alpine re-initialises the component and binds once per element, which is the behaviour
    we want on a partial that reappears with every page.

    **Raw localStorage, not `$persist`.** `$persist` JSON-encodes its value, so a boolean written here
    would read back as the string `"false"` to `resources/js/table-context-menu.js`, which uses `getItem`
    directly. Both sides use this key and the literal `'off'`, so there is nothing to disagree about. The
    domain rail partial documents the same seam.

    **The server holds the preference now; localStorage is its mirror.** The script reconciles the mirror
    from a server-rendered meta tag at load and exposes `window.tableContextMenuPersist`, the one POST
    implementation — this switch calls it rather than carrying a second copy of the fetch. The optional
    chaining is the graceful half: with the script somehow absent, the mirror still flips and this device
    still behaves.
--}}
<div
    x-data="{
        key: 'tableContextMenuDisabled',
        off: false,
        init() {
            this.off = this.read()

            // Another tab may have changed it. Cheap to honour and confusing not to.
            window.addEventListener('storage', (event) => {
                if (event.key === this.key) {
                    this.off = this.read()
                }
            })
        },
        read() {
            try {
                return localStorage.getItem(this.key) === 'off'
            } catch {
                return false
            }
        },
        toggle() {
            try {
                this.off ? localStorage.removeItem(this.key) : localStorage.setItem(this.key, 'off')
                this.off = this.read()
            } catch {
                // Private browsing. Nothing to store and nothing to say — Shift+right-click is the
                // escape hatch that needs no storage at all.
                this.off = ! this.off
            }

            window.tableContextMenuPersist?.(this.off)
        },
    }"
    class="fi-md-switch-row"
>
    <div class="fi-md-switch-row-text">
        <span id="table-context-menu-switch-label" class="fi-md-switch-row-title">{{ __('Right-click menus') }}</span>
        <span id="table-context-menu-switch-help" class="fi-md-switch-row-supporting">
            {{ __('Right-clicking a row in a table opens its actions. Shift + right-click always gives the browser\'s own menu.') }}
        </span>
    </div>

    <button
        type="button"
        role="switch"
        x-on:click="toggle()"
        x-bind:aria-checked="(! off).toString()"
        aria-checked="true"
        aria-labelledby="table-context-menu-switch-label"
        aria-describedby="table-context-menu-switch-help"
        class="fi-md-switch"
    >
        <span class="fi-md-switch-handle"></span>
    </button>
</div>
