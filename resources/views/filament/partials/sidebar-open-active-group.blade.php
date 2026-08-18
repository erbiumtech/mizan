{{--
    Opens the branch containing the current page.

    Filament seeds `collapsedGroups` in localStorage once and then treats it as the truth, and it
    never reconciles it against where you actually are — so a group you collapsed last week stays
    collapsed when you navigate into it today, and the entry for the page you are looking at is
    hidden inside it. With groups now closed by default (see DomainNavigationManager::collapsed) that
    stops being an edge case and becomes the first thing that happens.

    Rendered at SIDEBAR_NAV_END, which is immediately after Filament's own pre-hide script and before
    Alpine initialises. That position is what lets this correct both the stored state and the DOM
    while it is still cheap: Alpine's $persist reads localStorage on init, so writing it here means
    the store starts out right rather than being corrected a frame later.

    Only ever *opens*. Someone who collapses the group they are in keeps it collapsed until they
    navigate again — this runs on load, not on every interaction.
--}}
<script>
    (() => {
        const active = document.querySelector('.fi-main-sidebar .fi-sidebar-group.fi-active');

        if (! active) {
            return;
        }

        const label = active.dataset.groupLabel;

        if (! label) {
            return;
        }

        let collapsed;

        try {
            collapsed = JSON.parse(localStorage.getItem('collapsedGroups')) ?? [];
        } catch {
            // A malformed value is not worth throwing over: treat it as nothing collapsed and let
            // the write below put a well-formed array back.
            collapsed = [];
        }

        if (! Array.isArray(collapsed) || ! collapsed.includes(label)) {
            return;
        }

        localStorage.setItem(
            'collapsedGroups',
            JSON.stringify(collapsed.filter((group) => group !== label)),
        );

        // Undoes Filament's pre-hide for this one group, which has already run by this point.
        active.classList.remove('fi-collapsed');

        const items = active.querySelector('.fi-sidebar-group-items');

        if (items) {
            items.style.display = null;
        }

        // Alpine may already be running — after a wire:navigate the store survives the page swap, so
        // the localStorage write above would not be re-read. Keep the two in step.
        const store = window.Alpine?.store?.('sidebar');

        if (store && Array.isArray(store.collapsedGroups)) {
            store.collapsedGroups = store.collapsedGroups.filter((group) => group !== label);
        }
    })();
</script>
