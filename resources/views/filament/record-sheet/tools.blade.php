{{--
    The side sheet's tools, beside Filament's own close button — App\Filament\Support\RecordSheetActions.
    Rendered in the modal's description slot, which sits under the heading in Filament's markup;
    material.css lifts it onto the heading's row.
--}}
<span class="fi-record-sheet-tools">
    <button
        type="button"
        class="fi-record-sheet-tool"
        x-data="{ copied: false }"
        x-on:click="navigator.clipboard?.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 1500)"
        title="{{ __('Copy link') }}"
        aria-label="{{ __('Copy link') }}"
    >
        <svg x-show="! copied" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"></path></svg>
        <svg x-show="copied" x-cloak viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m5 12 5 5 9-10"></path></svg>
    </button>
    @if ($fullPageUrl)
        <a href="{{ $fullPageUrl }}" class="fi-record-sheet-tool" title="{{ __('Open the full page') }}" aria-label="{{ __('Open the full page') }}">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 4h6v6M20 4l-8 8M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path></svg>
        </a>
    @endif
</span>
