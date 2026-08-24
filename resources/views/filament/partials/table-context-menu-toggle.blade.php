{{--
    The right-click menu's off switch, in the user menu — docs/table-context-menu-plan.md §4.

    **This is the route back.** The context menu carries the same toggle, because that is where somebody
    annoyed by it will look — but a menu that has just switched itself off cannot switch itself back on,
    so the preference needs a home that is always reachable. This is it.

    Alpine rather than a script tag, and the distinction is §2's: the concern there is body scripts being
    re-executed on every `wire:navigate` and stacking `addEventListener` calls. `x-data` and `x-on:click`
    stack nothing — Alpine re-initialises the component and binds once per element, which is the behaviour
    we want on a partial that reappears with every page.

    **Raw localStorage, not `$persist`.** `$persist` JSON-encodes its value, so a boolean written here
    would read back as the string `"false"` to `resources/js/table-context-menu.js`, which uses `getItem`
    directly. Both sides use this key and the literal `'off'`, so there is nothing to disagree about. The
    domain rail partial documents the same seam.
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
            }
        },
    }"
    class="fi-dropdown-list"
>
    <button
        type="button"
        x-on:click="toggle()"
        class="fi-dropdown-list-item"
    >
        <span class="fi-dropdown-list-item-label">
            <span x-show="! off">{{ __('Turn off right-click menus') }}</span>
            <span x-show="off" x-cloak>{{ __('Turn on right-click menus') }}</span>
        </span>
    </button>
</div>
