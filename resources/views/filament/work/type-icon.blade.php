{{-- A work item's type as a small coloured glyph, the name kept for screen readers and the tooltip. --}}
@php($type = strtolower((string) $type))
<span @class(['fi-board-type-icon', 'fi-board-type-'.($type !== '' ? \Illuminate\Support\Str::slug($type) : 'task')]) title="{{ __(ucfirst($type)) }}">
    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
        @switch($type)
            @case('epic')
                <path d="M9 2 4 9h4l-1 5 5-7H8z" fill="currentColor"></path>
                @break
            @case('bug')
                <circle cx="8" cy="8" r="4" fill="currentColor"></circle>
                @break
            @case('story')
                <path d="M4 2h8v12l-4-3-4 3z" fill="currentColor"></path>
                @break
            @case('subtask')
                <rect x="3" y="3" width="10" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="2"></rect>
                @break
            @default
                <path d="m4.5 8.5 2.5 2.5 4.5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
        @endswitch
    </svg>
    <span class="sr-only">{{ __(ucfirst($type)) }}</span>
</span>
