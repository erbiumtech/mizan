{{--
    3a's search box and grid/list toggle, in the page's own header row.

    Rendered through PAGE_HEADER_ACTIONS_BEFORE scoped to the Reports page. That hook is rendered by
    the page's own Livewire component, which is what makes wire:model and wire:click here reach the
    page's state — the same controls placed in the sidebar could not.
--}}
<div class="fi-reports-header-controls">
    <label class="fi-reports-search">
        <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-reports-search-icon" />
        <input
            type="search"
            wire:model.live.debounce.200ms="query"
            placeholder="Filter reports"
            aria-label="Filter reports"
            class="fi-reports-search-input"
        >
    </label>

    <div class="fi-reports-toggle" role="group" aria-label="Layout">
        <button
            type="button"
            wire:click="$set('display', 'grid')"
            @class(['fi-reports-toggle-btn', 'fi-active' => $this->display === 'grid'])
            aria-pressed="{{ $this->display === 'grid' ? 'true' : 'false' }}"
        >Grid</button>
        <button
            type="button"
            wire:click="$set('display', 'list')"
            @class(['fi-reports-toggle-btn', 'fi-active' => $this->display === 'list'])
            aria-pressed="{{ $this->display === 'list' ? 'true' : 'false' }}"
        >List</button>
    </div>
</div>
