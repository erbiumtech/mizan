{{--
    The topbar search bar — docs/material-3-theme.md. Filament's own global search is disabled (see
    AdminPanelProvider), so this is the single search entry point: it opens the ⌘K command palette,
    which searches records the same way global search did (RecordProvider uses each resource's
    getGlobalSearchResults) and also covers resources, pages and commands.

    A real <button> that reads as Material's search bar: people click what looks like a field when they
    want to search, and a button is what opening a dialog is. It stretches across the free width of the
    topbar (material.css). A printable key pressed while it has focus opens the palette with that
    character already typed (`detail.query`, read by openPalette()), so tabbing to it and typing works
    the way a field would. ⌘K / Ctrl K still opens the palette from anywhere.
--}}
<button
    type="button"
    onclick="window.dispatchEvent(new CustomEvent('open-command-palette'))"
    onkeydown="if (event.key.length === 1 && ! event.metaKey && ! event.ctrlKey && ! event.altKey && event.key !== ' ') { event.preventDefault(); window.dispatchEvent(new CustomEvent('open-command-palette', { detail: { query: event.key } })) }"
    aria-label="{{ __('Search employees, invoices, work…') }}"
    aria-haspopup="dialog"
    class="fi-command-palette-trigger"
>
    <x-filament::icon
        icon="heroicon-m-magnifying-glass"
        class="fi-command-palette-trigger-icon"
    />

    <span class="fi-command-palette-trigger-placeholder">{{ __('Search employees, invoices, work…') }}</span>

    <kbd
        x-data
        x-text="/Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘K' : 'Ctrl K'"
        class="fi-command-palette-trigger-kbd"
        aria-hidden="true"
    >⌘K</kbd>
</button>

{{-- Narrow screens: the 40px search icon button alone, so the topbar does not crowd. --}}
<button
    type="button"
    onclick="window.dispatchEvent(new CustomEvent('open-command-palette'))"
    aria-label="{{ __('Search') }}"
    aria-haspopup="dialog"
    class="fi-command-palette-trigger-compact"
>
    <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-command-palette-trigger-icon" />
</button>
