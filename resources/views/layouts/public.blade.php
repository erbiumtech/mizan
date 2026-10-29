{{--
    The public pages — signup, careers, document verification. Self-contained like the status page: no Vite build,
    no panel session, nothing a visitor needs before they have a company.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @yield('head')
    <title>{{ config('app.name') }} · @yield('title')</title>
    <style>
        :root { color-scheme: light dark; --bg:#f8fafc; --card:#fff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0; --accent:#0f766e; --danger:#991b1b; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#0f172a; --card:#1e293b; --text:#e2e8f0; --muted:#94a3b8; --line:#334155; --accent:#2dd4bf; --danger:#fca5a5; }
        }
        * { box-sizing: border-box; }
        body { margin:0; padding:2rem 1rem; background:var(--bg); color:var(--text);
               font:16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .wrap { max-width:28rem; margin:0 auto; }
        h1 { font-size:1.5rem; margin:0 0 .25rem; }
        .muted { color:var(--muted); font-size:.875rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:.75rem; padding:1.25rem; margin:1rem 0; }
        label { display:block; font-weight:600; font-size:.875rem; margin:.75rem 0 .25rem; }
        input, select { width:100%; padding:.55rem .7rem; border:1px solid var(--line); border-radius:.5rem; background:var(--bg); color:var(--text); font:inherit; }
        button { width:100%; margin-top:1.25rem; padding:.7rem; border:0; border-radius:.5rem; background:var(--accent); color:#fff; font:inherit; font-weight:600; cursor:pointer; }
        .error { color:var(--danger); font-size:.875rem; margin:.25rem 0 0; }
        .hp { position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden; }
        a { color:var(--accent); }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('body')
    </div>
</body>
</html>
