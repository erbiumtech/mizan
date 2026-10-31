{{--
    The first level of the shell: Material's navigation rail, as m3.material.io draws it.

    From the top: one destination per domain, an icon in a 56×32 pill over its label, and at the foot
    a round light/dark button. Search lives in the topbar's search bar, and showing or hiding the
    drawer is the topbar's own menu button, as it was before the rail.

    Rendered through the LAYOUT_START hook, which puts it as the first child of `.fi-layout` — ahead
    of the sidebar and in the same flex row. That position is why no Filament view had to be published
    to add a navigation level.

    The second level is the drawer beside it (nav-drawer partial, rendered inside Filament's own
    sidebar). Choosing a destination here swaps the drawer to that domain's screens without leaving
    the page — the drawer is where you navigate, the rail is where you choose what the drawer shows.
    Each destination is still a real link to the domain's first screen: before Alpine has booted, or
    with a modifier key held to open a new tab, it navigates as a link always has.

    The drawer's open state is Filament's own sidebar store, so the topbar's menu button and a rail
    destination agree, and the choice is remembered per
    person by the store's $persist. Which domain the drawer shows is held in a small store of our own
    (`mizanNav`), reset to the screen's domain on every page so a soft navigation never leaves the
    drawer showing a domain the page is not in.

    The user avatar stays in the topbar: that avatar is Filament's user menu — profile, password,
    log out, exit impersonation — and re-hosting it outside the topbar it is built for risks breaking
    the way out of an impersonation session.
--}}
@php
    $railDomains = app(\App\Filament\Navigation\NavigationSnapshot::class)->rail();
    $currentDomain = collect($railDomains)->firstWhere('active', true)['key'] ?? ($railDomains[0]['key'] ?? null);
@endphp

@if (count($railDomains) > 1)
    {{--
        The drawer starts closed on a phone and open everywhere else.

        Filament's store opens both by default and writes that default the first time it initialises,
        so the phone's key is seeded before Alpine boots — this is inside `.fi-layout`, well ahead of
        the scripts. Only seeded when nothing is stored, so it stays a preference.

        The domain store is registered here rather than in a bundle because it belongs to this
        partial: on a soft navigation the script runs again with Alpine already started, which is why
        it registers immediately when it can and on `alpine:init` when it cannot.
    --}}
    <script>
        (() => {
            try {
                if (localStorage.getItem('isOpen') === null) {
                    localStorage.setItem('isOpen', 'false');
                }
            } catch {}

            const register = () => {
                if (! window.Alpine.store('mizanNav')) {
                    window.Alpine.store('mizanNav', { domain: @js($currentDomain) });
                }

                window.Alpine.store('mizanNav').domain = @js($currentDomain);
            };

            if (window.Alpine?.store) {
                register();
            } else {
                document.addEventListener('alpine:init', register, { once: true });
            }
        })();
    </script>

    <nav class="fi-domain-rail" aria-label="{{ __('Domains') }}" x-data="{}">
        <ul class="fi-domain-rail-items">
            @foreach ($railDomains as $domain)
                {{--
                    A click picks the domain for the drawer and opens it; it does not navigate. A
                    modified click (new tab, new window) is left to the browser, so the link still
                    does what a link does. `fi-active` is the screen's own domain as the server saw
                    it, so the right destination is marked before Alpine runs; the binding then
                    follows the drawer. No `wire:navigate`: Livewire's own click handler would race
                    the one that keeps a plain click from navigating, and the only clicks that do
                    navigate here (modified ones, or before Alpine) are better off as real loads.
                --}}
                <li class="fi-domain-rail-slot">
                    <a
                        href="{{ $domain['url'] }}"
                        x-on:click="if (! ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey)) { $event.preventDefault(); $event.stopImmediatePropagation(); $store.mizanNav.domain = @js($domain['key']); $store.sidebar.open() }"
                        x-bind:class="{ 'fi-active': $store.mizanNav?.domain === @js($domain['key']) }"
                        @class([
                            'fi-domain-rail-item',
                            'fi-active' => $domain['active'],
                        ])
                        @if ($domain['active']) aria-current="page" @endif
                    >
                        {{ \Filament\Support\generate_icon_html($domain['icon'], size: \Filament\Support\Enums\IconSize::Medium) }}
                        <span class="fi-domain-rail-item-label">{{ __($domain['label']) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="fi-domain-rail-footer">
            {{-- Filament's own theme switch, by its own event: it stores the choice and flips the class. --}}
            <button
                type="button"
                class="fi-domain-rail-action"
                x-on:click="$dispatch('theme-changed', $store.theme === 'dark' ? 'light' : 'dark')"
                x-bind:aria-label="$store.theme === 'dark' ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))"
                aria-label="{{ __('Switch to dark mode') }}"
            >
                <span class="fi-domain-rail-action-dark">{{ \Filament\Support\generate_icon_html('heroicon-o-moon', size: \Filament\Support\Enums\IconSize::Medium) }}</span>
                <span class="fi-domain-rail-action-light">{{ \Filament\Support\generate_icon_html('heroicon-o-sun', size: \Filament\Support\Enums\IconSize::Medium) }}</span>
            </button>
        </div>
    </nav>
@endif
