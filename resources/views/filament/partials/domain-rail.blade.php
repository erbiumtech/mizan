{{--
    The first level of the shell: one icon per domain, 3a's 84px rail.

    Rendered through the LAYOUT_START hook, which puts it as the first child of `.fi-layout` —
    ahead of the sidebar and in the same flex row. That position is why no Filament view had to be
    published to add a whole navigation level.

    The user avatar sits in the topbar rather than at the foot of this rail as 3a draws it. The
    avatar there is Filament's user menu — profile, password, log out, impersonation exit — and
    re-hosting that component outside the topbar it is built for risks breaking the way out of an
    impersonation session. The rail carries the brand mark instead, and the menu stays where it
    works.
--}}
@php
    $railDomains = \App\Support\NavigationDomains::rail(
        app(\App\Filament\Navigation\NavigationSnapshot::class)->groups(),
    );
@endphp

@if (count($railDomains) > 1)
    <nav class="fi-domain-rail" aria-label="{{ __('Domains') }}">
        <a href="{{ filament()->getUrl() }}" class="fi-domain-rail-brand" aria-label="{{ filament()->getBrandName() }}">
            <img src="{{ filament()->getBrandLogo() }}" alt="" class="fi-domain-rail-brand-logo">
        </a>

        <ul class="fi-domain-rail-items">
            @foreach ($railDomains as $domain)
                <li>
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
                </li>
            @endforeach
        </ul>
    </nav>
@endif
