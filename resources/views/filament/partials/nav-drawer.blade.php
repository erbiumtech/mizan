{{--
    The second level of the shell: Material's navigation drawer, beside the rail.

    One panel per domain on the rail, each that domain's screens. The rail picks which panel shows
    (the `mizanNav` store, see the domain-rail partial); the server marks the screen's own domain,
    so the right panel is the one on screen before Alpine runs and the others carry `hidden`.

    Rendered through SIDEBAR_NAV_START, inside Filament's own sidebar, so the drawer inherits
    everything Filament already does with that column: docked beside the content on a desktop,
    a modal sheet with a scrim on a phone, opened and closed through the same store as the topbar's
    menu button, and remembered per person. Filament's own groups below this hook show only the
    current domain and are hidden by the theme — this drawer says the same thing for every domain.

    A branch is a <details>: it opens and closes with no script, before Alpine and with a keyboard,
    and the branch holding the screen being viewed is rendered open. A domain with one branch, or
    with screens that sit in no branch, lists them directly.

    One line per item, and no icon, for the measured reason PanelPerformanceTest records: this loop
    runs once per screen of every licensed module on every page, so its indentation and its icons
    are the page's bytes. The attributes the line carries: `wire:navigate` because the panel is in
    SPA mode, `fi-active` and aria-current on the screen being viewed, and the item's badge — already
    computed when the navigation was built, so it costs no query here.

    Reports lists its categories, as the flyout did; on a reports page each category also opens to
    its reports, which is when they are worth their bytes.

    On a phone the rail is not drawn, so the panel carries the domains itself, as a row of chips
    above the list (`fi-nav-drawer-domains`, shown by the theme below 840px only).
--}}
@php
    $railDomains = app(\App\Filament\Navigation\NavigationSnapshot::class)->rail();
    $reports = \App\Modules\Core\Filament\Pages\Reports::class;
@endphp

@if (count($railDomains) > 1)
    <div class="fi-nav-drawer" x-data="{}">
        <div class="fi-nav-drawer-domains" role="group" aria-label="{{ __('Domains') }}">
            @foreach ($railDomains as $domain)
                <button type="button" x-on:click="$store.mizanNav.domain = @js($domain['key'])" x-bind:aria-pressed="($store.mizanNav?.domain === @js($domain['key'])).toString()" aria-pressed="{{ $domain['active'] ? 'true' : 'false' }}" class="fi-nav-drawer-domain">{{ __($domain['label']) }}</button>
            @endforeach
        </div>

        @foreach ($railDomains as $domain)
            <section
                class="fi-nav-drawer-panel"
                data-domain="{{ $domain['key'] }}"
                aria-label="{{ __($domain['label']) }}"
                x-bind:hidden="$store.mizanNav?.domain !== @js($domain['key'])"
                @unless ($domain['active']) hidden @endunless
            >
                <h2 class="fi-nav-drawer-headline">{{ __($domain['label']) }}</h2>

                @if ($domain['key'] === 'reports' && $reports::canAccess())
                    @php($onReports = $domain['active'])
                    @php($section = request()->query('section'))
                    <ul class="fi-nav-drawer-list">
                        <li><a href="{{ $reports::getUrl() }}" wire:navigate @class(['fi-nav-drawer-item', 'fi-active' => $onReports && blank($section) && request()->url() === $reports::getUrl()])><span class="fi-nav-drawer-item-label">{{ __('All reports') }}</span><span class="fi-nav-drawer-item-badge">{{ $reports::total() }}</span></a></li>
                        @if ($onReports)
                            @foreach ($reports::sections() as $name => $links)
                                <li><details class="fi-nav-drawer-branch" @if ($section === $name || collect($links)->contains('url', request()->url())) open @endif><summary class="fi-nav-drawer-item">
                                    <span class="fi-nav-drawer-item-label">{{ $name }}</span><span class="fi-nav-drawer-item-badge">{{ count($links) }}</span></summary>
                                    <ul class="fi-nav-drawer-list">
                                        <li><a href="{{ $reports::getUrl() }}?section={{ urlencode($name) }}" wire:navigate @class(['fi-nav-drawer-item', 'fi-active' => $section === $name])><span class="fi-nav-drawer-item-label">{{ __('All in :section', ['section' => $name]) }}</span></a></li>
                                        @foreach ($links as $link)
                                            <li><a href="{{ $link['url'] }}" wire:navigate @class(['fi-nav-drawer-item', 'fi-active' => request()->url() === $link['url']])><span class="fi-nav-drawer-item-label">{{ $link['label'] }}</span></a></li>
                                        @endforeach
                                    </ul>
                                </details></li>
                            @endforeach
                        @else
                            @foreach ($reports::sectionCounts() as $name => $count)
                                <li><a href="{{ $reports::getUrl() }}?section={{ urlencode($name) }}" wire:navigate class="fi-nav-drawer-item"><span class="fi-nav-drawer-item-label">{{ $name }}</span><span class="fi-nav-drawer-item-badge">{{ $count }}</span></a></li>
                            @endforeach
                        @endif
                    </ul>
                @else
                    @php($branches = count(array_filter($domain['groups'], fn ($group) => filled($group->getLabel()))))
                    <ul class="fi-nav-drawer-list">
                        @foreach ($domain['groups'] as $group)
                            @if ($branches > 1 && filled($group->getLabel()))
                                <li><details class="fi-nav-drawer-branch" @if ($group->isActive()) open @endif><summary class="fi-nav-drawer-item"><span class="fi-nav-drawer-item-label">{{ $group->getLabel() }}</span></summary>
                                    <ul class="fi-nav-drawer-list">
                                        @foreach (collect($group->getItems()) as $item)
                                            <li><a href="{{ $item->getUrl() }}" wire:navigate @class(['fi-nav-drawer-item', 'fi-active' => $item->isActive()]) @if ($item->isActive()) aria-current="page" @endif><span class="fi-nav-drawer-item-label">{{ $item->getLabel() }}</span>@if (filled($badge = $item->getBadge()))<span class="fi-nav-drawer-item-badge">{{ $badge }}</span>@endif</a></li>
                                        @endforeach
                                    </ul>
                                </details></li>
                            @else
                                @foreach (collect($group->getItems()) as $item)
                                    <li><a href="{{ $item->getUrl() }}" wire:navigate @class(['fi-nav-drawer-item', 'fi-active' => $item->isActive()]) @if ($item->isActive()) aria-current="page" @endif><span class="fi-nav-drawer-item-label">{{ $item->getLabel() }}</span>@if (filled($badge = $item->getBadge()))<span class="fi-nav-drawer-item-badge">{{ $badge }}</span>@endif</a></li>
                                @endforeach
                            @endif
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
@endif
