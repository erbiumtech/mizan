{{--
    The one logo — docs/material-3-theme.md, "Top app bar".

    The company's logo and name, once, in the topbar beside the bell and the avatar. Filament's own
    brand (sidebar header, topbar start) and the domain rail's brand mark are hidden or gone so nothing
    repeats it. The logo is the panel's brand logo, which is the current company's own where it has
    uploaded one (AdminPanelProvider::brandLogo), with the dark-mode variant for dark mode.

    It is also the company switcher. Filament's tenant menu lived in the sidebar column; it is hidden
    there (material.css) and this takes its place, so there is one trigger, not two. A person in one
    company gets a plain link to the dashboard — the rail's old brand mark did that — and a person in
    several gets the same mark as a menu: the dashboard first, then each other company.
--}}
@php
    $user = filament()->auth()->user();
    $current = filament()->getTenant();
    $name = filament()->getBrandName();
    $logo = filament()->getBrandLogo();
    $darkLogo = filament()->getDarkModeBrandLogo() ?? $logo;
    $home = filament()->getHomeUrl() ?? filament()->getUrl();

    // The same list Filament's own switcher shows (HasTenantMenu::getSwitchableTenants): the user's
    // companies, memoised per request on the user, so this costs no query the page did not already run.
    // Opening one still goes through the tenant middleware's canAccessTenant().
    $others = ($user && $current && filament()->hasTenancy() && filament()->hasTenantSwitcher())
        ? collect(filament()->getUserTenants($user))
            ->reject(fn ($tenant): bool => $tenant->is($current))
            ->values()
        : collect();
@endphp

@if ($others->isEmpty())
    <a
        {{ \Filament\Support\generate_href_html($home) }}
        class="fi-company-brand"
        aria-label="{{ $name }} — {{ __('Dashboard') }}"
    >
        @if (filled($logo))
            <img src="{{ $logo }}" alt="" class="fi-company-brand-logo fi-company-brand-logo-light">
            <img src="{{ $darkLogo }}" alt="" class="fi-company-brand-logo fi-company-brand-logo-dark">
        @endif
        <span class="fi-company-brand-name">{{ $name }}</span>
    </a>
@else
    <x-filament::dropdown placement="bottom-end" teleport class="fi-company-brand-menu">
        <x-slot name="trigger">
            <button type="button" class="fi-company-brand" aria-label="{{ $name }} — {{ __('Switch company') }}">
                @if (filled($logo))
                    <img src="{{ $logo }}" alt="" class="fi-company-brand-logo fi-company-brand-logo-light">
                    <img src="{{ $darkLogo }}" alt="" class="fi-company-brand-logo fi-company-brand-logo-dark">
                @endif
                <span class="fi-company-brand-name">{{ $name }}</span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="fi-company-brand-chevron" />
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item tag="a" :href="$home" icon="heroicon-o-home">
                {{ __('Dashboard') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>

        <x-filament::dropdown.list class="fi-company-brand-tenants">
            @foreach ($others as $tenant)
                <x-filament::dropdown.list.item tag="a" :href="filament()->getUrl($tenant)" icon="heroicon-o-building-office-2">
                    {{ filament()->getTenantName($tenant) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
