{{--
    The public site's layout — docs/company-website-plan.md.

    Indexable, where layouts/public is noindex; wider, because a pricing table does not fit in
    28rem; and still a stylesheet in the file, because the site must render on a host where the
    Vite build has not run, the same reason the status and careers pages were built this way.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    <style>
        :root { color-scheme: light dark; --bg:#f8fafc; --card:#fff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0; --accent:#0f766e; --accent-ink:#fff; --danger:#991b1b; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#0f172a; --card:#1e293b; --text:#e2e8f0; --muted:#94a3b8; --line:#334155; --accent:#2dd4bf; --accent-ink:#042f2e; --danger:#fca5a5; }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        a { color:var(--accent); }
        .wrap { max-width:64rem; margin:0 auto; padding:0 1rem; }
        header.top { border-bottom:1px solid var(--line); background:var(--card); }
        header.top .wrap { display:flex; align-items:center; gap:1.25rem; padding:.9rem 1rem; flex-wrap:wrap; }
        .brand { font-weight:700; text-decoration:none; color:var(--text); font-size:1.1rem; margin-right:auto; }
        nav a { text-decoration:none; color:var(--text); font-size:.95rem; }
        nav a.cta { background:var(--accent); color:var(--accent-ink); padding:.45rem .9rem; border-radius:.5rem; font-weight:600; }
        main { padding:2.5rem 0 3rem; }
        h1 { font-size:2rem; line-height:1.2; margin:0 0 .5rem; }
        h2 { font-size:1.35rem; margin:2.5rem 0 .75rem; }
        h3 { font-size:1.05rem; margin:0 0 .25rem; }
        .lede { font-size:1.15rem; color:var(--muted); max-width:44rem; margin:0 0 1.5rem; }
        .muted { color:var(--muted); font-size:.9rem; }
        .grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit, minmax(16rem, 1fr)); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:.75rem; padding:1.25rem; }
        .card.trial { border-color:var(--accent); }
        .price { font-size:1.75rem; font-weight:700; margin:.5rem 0 0; }
        .price small { font-size:.85rem; font-weight:400; color:var(--muted); }
        .btn { display:inline-block; background:var(--accent); color:var(--accent-ink); padding:.6rem 1.1rem; border-radius:.5rem; text-decoration:none; font-weight:600; border:0; font:inherit; cursor:pointer; }
        .btn.ghost { background:transparent; color:var(--accent); border:1px solid var(--accent); }
        ul.plain { list-style:none; padding:0; margin:.75rem 0 0; }
        ul.plain li { padding:.2rem 0; border-top:1px solid var(--line); font-size:.95rem; }
        ul.plain li:first-child { border-top:0; }
        .pill { display:inline-block; font-size:.75rem; background:var(--bg); border:1px solid var(--line); border-radius:999px; padding:.1rem .6rem; color:var(--muted); }
        .currencies a { margin-right:.6rem; }
        .currencies a.on { font-weight:700; text-decoration:none; color:var(--text); }
        label { display:block; font-weight:600; font-size:.875rem; margin:.75rem 0 .25rem; }
        input, textarea { width:100%; padding:.55rem .7rem; border:1px solid var(--line); border-radius:.5rem; background:var(--bg); color:var(--text); font:inherit; }
        .error { color:var(--danger); font-size:.875rem; margin:.25rem 0 0; }
        .hp { position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden; }
        .prose h2 { margin-top:1.75rem; } .prose img { max-width:100%; }
        footer { border-top:1px solid var(--line); padding:1.5rem 0 2.5rem; color:var(--muted); font-size:.9rem; }
        footer .wrap { display:flex; flex-wrap:wrap; gap:1rem 2rem; }
        footer a { color:var(--muted); }
        .residency { font-size:.9rem; color:var(--muted); }
    </style>
    @yield('head')
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="brand" href="{{ route('site.home') }}">{{ config('app.name') }}</a>
            <nav>
                <a href="{{ route('site.features') }}">Features</a>
            </nav>
            <nav><a href="{{ route('site.pricing') }}">Pricing</a></nav>
            <nav><a href="{{ route('site.contact') }}">Contact</a></nav>
            <nav><a href="{{ \Filament\Facades\Filament::getPanel('admin')->getLoginUrl() }}">Sign in</a></nav>
            <nav><a class="cta" href="{{ route('signup') }}">Start a trial</a></nav>
        </div>
    </header>

    <main>
        <div class="wrap">
            @yield('body')
        </div>
    </main>

    <footer>
        <div class="wrap">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}@if($settings['footer']) · {{ $settings['footer'] }}@endif</span>
            <span class="residency">{{ $residency }}</span>
            @foreach($pages as $page)
                <a href="{{ route('site.page', ['slug' => $page->slug]) }}">{{ $page->title }}</a>
            @endforeach
            @if($settings['contact_email'])<a href="mailto:{{ $settings['contact_email'] }}">{{ $settings['contact_email'] }}</a>@endif
        </div>
    </footer>
</body>
</html>
