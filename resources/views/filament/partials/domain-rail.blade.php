{{--
    The first level of the shell, and by default the only one on screen: a 68px rail of domains, with
    each domain's whole tree one hover away in a flyout.

    Rendered through the LAYOUT_START hook, which puts it as the first child of `.fi-layout` — ahead
    of the sidebar and in the same flex row. That position is why no Filament view had to be published
    to add a navigation level.

    The flyouts are shown by CSS on hover and on focus-within, deliberately, rather than by Alpine.
    They are navigation: they have to work on the first paint, before Livewire has booted, and they
    have to open for a keyboard as readily as for a pointer. A hover panel driven by JS state also
    keeps opening after a `wire:navigate` swap has moved the pointer, which is the bug every one of
    these grows eventually.

    The user avatar stays in the topbar rather than at the foot of the rail as 3a draws it: that avatar
    is Filament's user menu — profile, password, log out, exit impersonation — and re-hosting it
    outside the topbar it is built for risks breaking the way out of an impersonation session.
--}}
@php
    $railDomains = \App\Support\NavigationDomains::rail(
        app(\App\Filament\Navigation\NavigationSnapshot::class)->groups(),
    );
@endphp

@if (count($railDomains) > 1)
    {{--
        The column starts closed, so the content gets the width back.

        Seeded here rather than configured on the panel because Filament has no "start collapsed"
        option: the state lives in localStorage through Alpine's $persist, which defaults to open and
        writes that default the first time it initialises. Setting the keys before Alpine boots — this
        is inside `.fi-layout`, well ahead of the scripts — makes closed the starting point while
        leaving it a preference, so anyone who opens the column keeps it open.
    --}}
    <script>
        (() => {
            for (const key of ['isOpen', 'isOpenDesktop']) {
                if (localStorage.getItem(key) === null) {
                    localStorage.setItem(key, 'false');
                }
            }
        })();
    </script>

    <nav class="fi-domain-rail" aria-label="{{ __('Domains') }}">
        <a href="{{ filament()->getUrl() }}" class="fi-domain-rail-brand" aria-label="{{ filament()->getBrandName() }}">
            <img src="{{ filament()->getBrandLogo() }}" alt="" class="fi-domain-rail-brand-logo">
        </a>

        <ul class="fi-domain-rail-items">
            @foreach ($railDomains as $domain)
                <li class="fi-domain-rail-slot">
                    <a
                        href="{{ $domain['url'] }}"
                        wire:navigate
                        @class([
                            'fi-domain-rail-item',
                            'fi-active' => $domain['active'],
                        ])
                        @if ($domain['active']) aria-current="page" @endif
                    >
                        {{ \Filament\Support\generate_icon_html($domain['icon'], size: \Filament\Support\Enums\IconSize::Medium) }}
                        <span class="fi-domain-rail-item-label">{{ $domain['label'] }}</span>
                    </a>

                    {{--
                        The flyout. Its columns are decided in PHP (NavigationDomains::columns) rather
                        than by CSS multi-column: multicol needs a definite height to fragment into
                        further columns, and every way of giving it one either clips a long domain or
                        stretches a short one to full height. Packing the groups server-side means the
                        panel is exactly as wide as it needs to be, never scrolls, and can be asserted.
                    --}}
                    <div class="fi-domain-flyout" role="group" aria-label="{{ $domain['label'] }}">
                        <div class="fi-domain-flyout-header">
                            <span class="fi-domain-flyout-title">{{ $domain['label'] }}</span>
                        </div>

                        {{--
                            Reports gets its categories here rather than its one hub link.

                            The categories used to live in the column, and the column is closed by
                            default now — so without this the only way to filter reports by category is
                            to open a panel that this rail exists to make unnecessary. The domain's
                            tree holds a single entry ("Reports"), which would make this the one
                            flyout that says less than its icon already does.
                        --}}
                        @if ($domain['key'] === 'reports' && \App\Modules\Core\Filament\Pages\Reports::canAccess())
                            @php($reports = \App\Modules\Core\Filament\Pages\Reports::class)

                            <div class="fi-domain-flyout-columns">
                                <div class="fi-domain-flyout-column">
                                    <div class="fi-domain-flyout-group">
                                        <ul class="fi-domain-flyout-list">
                                            <li>
                                                <a href="{{ $reports::getUrl() }}" wire:navigate class="fi-domain-flyout-item">
                                                    <span class="fi-domain-flyout-item-label">All reports</span>
                                                    <span class="fi-domain-flyout-item-count">{{ $reports::total() }}</span>
                                                </a>
                                            </li>

                                            @foreach ($reports::sectionCounts() as $section => $count)
                                                <li>
                                                    <a
                                                        href="{{ $reports::getUrl() }}?section={{ urlencode($section) }}"
                                                        wire:navigate
                                                        class="fi-domain-flyout-item"
                                                    >
                                                        <span class="fi-domain-flyout-item-label">{{ $section }}</span>
                                                        <span class="fi-domain-flyout-item-count">{{ $count }}</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        @else
                        <div class="fi-domain-flyout-columns">
                            @foreach ($domain['columns'] as $column)
                                <div class="fi-domain-flyout-column">
                                    @foreach ($column as $group)
                                        <div class="fi-domain-flyout-group">
                                            @if (filled($group->getLabel()))
                                                <div class="fi-domain-flyout-group-label">{{ $group->getLabel() }}</div>
                                            @endif

                                            <ul class="fi-domain-flyout-list">
                                                @foreach (collect($group->getItems()) as $item)
                                                    <li>
                                                        <a
                                                            href="{{ $item->getUrl() }}"
                                                            wire:navigate
                                                            @class([
                                                                'fi-domain-flyout-item',
                                                                'fi-active' => $item->isActive(),
                                                            ])
                                                        >
                                                            {{ \Filament\Support\generate_icon_html($item->getIcon(), size: \Filament\Support\Enums\IconSize::Small) }}
                                                            <span class="fi-domain-flyout-item-label">{{ $item->getLabel() }}</span>
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
