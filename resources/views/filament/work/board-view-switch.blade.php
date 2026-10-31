{{--
    Material 3 segmented button: List | Board | Timeline for the board's project. Links, not buttons —
    each is another page — with the current one marked and given a leading check.
--}}
<nav class="fi-md-segmented" aria-label="{{ __('View') }}">
    @foreach ($links as $link)
        @if ($link['current'])
            {{-- A link like the others, so the segments are one keyboard sequence; aria-current says which is open. --}}
            <a href="{{ $link['url'] }}" class="fi-md-segment fi-selected" aria-current="page" wire:navigate>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m5 12 5 5 9-10"></path></svg>
                {{ $link['label'] }}
            </a>
        @elseif ($link['url'])
            <a href="{{ $link['url'] }}" class="fi-md-segment" wire:navigate>{{ $link['label'] }}</a>
        @endif
    @endforeach
</nav>
