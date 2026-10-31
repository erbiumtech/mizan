{{--
    What makes the panel installable — docs/desktop-mobile-plan.md M1.

    The manifest is asked for with the current company's slug so the home-screen icon is that company's
    install (name, start URL); `use-credentials` so a custom host's session travels with the request. The
    worker registers once per page load and is idempotent in the browser: `wire:navigate` re-runs this
    script, but a second register() of the same URL is a no-op, not a second worker.
--}}
@php
    $slug = \Filament\Facades\Filament::getTenant()?->slug ?? \App\Support\CustomDomains::hostCompany()?->slug;
    $manifest = url('/manifest.webmanifest'.($slug ? '?company='.urlencode($slug) : ''));
@endphp
<link rel="manifest" href="{{ $manifest }}" crossorigin="use-credentials">
<meta name="theme-color" content="{{ \App\Providers\Filament\AdminPanelProvider::BRAND_GREEN }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ \Filament\Facades\Filament::getTenant()?->name ?? config('app.name') }}">
<link rel="apple-touch-icon" href="{{ asset('images/logo-mark.png') }}">
{{-- M2: the key a browser subscribes with. Absent when push is not configured, and the user-menu switch then renders nothing. --}}
@if ($vapid = \App\Support\WebPush::publicKey())
    <meta name="webpush-public-key" content="{{ $vapid }}">
@endif
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {}));
    }
</script>
