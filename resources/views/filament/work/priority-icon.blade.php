{{-- Priority as the familiar glyph: chevrons up for high, two bars for medium, chevrons down for low. --}}
@php($priority = strtolower((string) $priority))
<span @class([
    'fi-board-priority-icon',
    'fi-board-priority-high' => in_array($priority, ['highest', 'high'], true),
    'fi-board-priority-medium' => $priority === 'medium',
    'fi-board-priority-low' => in_array($priority, ['low', 'lowest'], true),
]) title="{{ __('Priority') }}: {{ __(ucfirst($priority)) }}">
    <svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @switch($priority)
            @case('highest')
                <path d="m3 8 5-4 5 4M3 12l5-4 5 4"></path>
                @break
            @case('high')
                <path d="m3 10 5-4 5 4"></path>
                @break
            @case('low')
                <path d="m3 6 5 4 5-4"></path>
                @break
            @case('lowest')
                <path d="m3 4 5 4 5-4M3 8l5 4 5-4"></path>
                @break
            @default
                <path d="M3 6h10M3 10h10"></path>
        @endswitch
    </svg>
    <span class="sr-only">{{ __(ucfirst($priority)) }}</span>
</span>
