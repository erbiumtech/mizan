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

    Every report is listed under its category, and each one goes to that report's own full page. The
    two destinations are deliberately different, because they are different things: a category row
    filters the explorer, where a report opens in the pane beside the list; a report row opens the
    report on its own screen, which is where its actions live — the register's inline edits, the file
    reports' release and batch reference. Listing only the categories meant the sidebar could say a
    company had seventeen reports without naming one of them, and the full pages were reachable only
    from inside the explorer.
--}}
@php
    $reports = \App\Modules\Core\Filament\Pages\Reports::class;
    $sections = $reports::sections();
    $current = request()->query('section');
    $base = $reports::getUrl();
    $here = request()->url();
@endphp

<ul class="fi-report-categories">
    <li>
        <a
            href="{{ $base }}"
            wire:navigate
            @class(['fi-report-category', 'fi-active' => blank($current) && $here === $base])
        >
            <span class="fi-report-category-label">All reports</span>
            <span class="fi-report-category-count">{{ $reports::total() }}</span>
        </a>
    </li>

    @foreach ($sections as $section => $links)
        <li>
            <a
                href="{{ $base }}?section={{ urlencode($section) }}"
                wire:navigate
                @class(['fi-report-category', 'fi-active' => $current === $section])
            >
                <span class="fi-report-category-label">{{ $section }}</span>
                <span class="fi-report-category-count">{{ count($links) }}</span>
            </a>

            <ul class="fi-report-list">
                {{--
                    One line per link, for the reason the explorer's rows are one line each: this loop runs
                    once per report in the company — fifty-two of them, on every page in the Reports domain —
                    and the indentation of a block repeated that often is kilobytes of a page that has a
                    measured ceiling. See `filament/pages/reports.blade.php`.

                    Active when that report's own page is the page being viewed. Compared on the URL rather
                    than asked of the route, because these are pages from four modules and a route name is
                    not something this partial can know.
                --}}
                @foreach ($links as $link)
                    <li><a href="{{ $link['url'] }}" wire:navigate @class(['fi-report-link', 'fi-active' => $here === $link['url']])>{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ul>
