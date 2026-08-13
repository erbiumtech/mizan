{{--
    3a's CATEGORIES list, in the column, for the Reports domain only.

    Rendered from the column partial rather than through a page-scoped render hook, because Filament
    renders SIDEBAR_NAV_START without scopes — the sidebar is its own Livewire component and knows
    nothing about which page is open. Branching on the domain gets to the same place and is what the
    design describes anyway: the column belongs to the domain, and in this domain the domain is
    reports.

    Links rather than wire:click, for the same reason the filter lives in the query string: the
    sidebar is a different Livewire component from the page, so it cannot set the page's state
    directly, and a link that carries the filter is also a link somebody can send.
--}}
@php
    $reports = \App\Modules\Core\Filament\Pages\Reports::class;
    $counts = $reports::sectionCounts();
    $current = request()->query('section');
    $base = $reports::getUrl();
@endphp

<ul class="fi-report-categories">
    <li>
        <a
            href="{{ $base }}"
            wire:navigate
            @class(['fi-report-category', 'fi-active' => blank($current)])
        >
            <span class="fi-report-category-label">All reports</span>
            <span class="fi-report-category-count">{{ $reports::total() }}</span>
        </a>
    </li>

    @foreach ($counts as $section => $count)
        <li>
            <a
                href="{{ $base }}?section={{ urlencode($section) }}"
                wire:navigate
                @class(['fi-report-category', 'fi-active' => $current === $section])
            >
                <span class="fi-report-category-label">{{ $section }}</span>
                <span class="fi-report-category-count">{{ $count }}</span>
            </a>
        </li>
    @endforeach
</ul>
