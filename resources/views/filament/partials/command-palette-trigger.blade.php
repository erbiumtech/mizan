{{--
    The topbar search field. Filament's own global search is disabled (see
    AdminPanelProvider), so this is the single search entry point: it opens the
    ⌘K command palette, which searches records the same way global search did
    (RecordProvider uses each resource's getGlobalSearchResults) and additionally
    covers resources, pages and commands.

    Styled to read as an input rather than a button, because that is what people
    click on when they want to search.
--}}
<button
    type="button"
    onclick="window.dispatchEvent(new CustomEvent('open-command-palette'))"
    title="Search (⌘K)"
    aria-label="Search"
    class="hidden h-9 w-56 items-center gap-2 rounded-lg bg-white pe-2 ps-3 text-sm text-gray-400 ring-1 ring-gray-950/10 transition hover:ring-gray-950/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 md:flex lg:w-72 dark:bg-white/5 dark:text-gray-500 dark:ring-white/10 dark:hover:ring-white/20"
>
    <x-filament::icon
        icon="heroicon-m-magnifying-glass"
        class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500"
    />

    <span class="flex-1 text-start">Search</span>

    <kbd class="hidden rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-sans text-xs font-medium text-gray-400 lg:inline-block dark:border-white/10 dark:bg-white/5 dark:text-gray-500">
        ⌘K
    </kbd>
</button>

{{-- Small screens: the icon alone, so the topbar doesn't crowd. --}}
<button
    type="button"
    onclick="window.dispatchEvent(new CustomEvent('open-command-palette'))"
    title="Search"
    aria-label="Search"
    class="flex items-center justify-center rounded-lg p-2 text-gray-400 transition hover:bg-gray-50 md:hidden dark:text-gray-500 dark:hover:bg-white/5"
>
    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-5 w-5" />
</button>
