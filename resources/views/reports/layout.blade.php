<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f6f8; color: #1a202c; padding: 2rem; }
        .report { max-width: 900px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 2rem; }
        h1 { font-size: 1.4rem; margin-bottom: .25rem; }
        .meta { color: #718096; font-size: .85rem; margin-bottom: 1.5rem; }
        .toolbar { display: flex; gap: .75rem; align-items: end; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .toolbar label { display: block; font-size: .75rem; color: #4a5568; margin-bottom: .25rem; }
        .toolbar input[type=date] { padding: .4rem .6rem; border: 1px solid #cbd5e0; border-radius: 6px; font-size: .85rem; }
        .btn { display: inline-block; padding: .5rem 1rem; border-radius: 6px; font-size: .85rem; border: none; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #4c51bf; color: #fff; }
        .btn-secondary { background: #edf2f7; color: #2d3748; }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        th, td { padding: .5rem .75rem; text-align: left; }
        th { background: #f7fafc; color: #4a5568; font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; border-bottom: 2px solid #e2e8f0; }
        td { border-bottom: 1px solid #edf2f7; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .section-row td { background: #f7fafc; font-weight: 600; text-transform: capitalize; }
        .subtotal td { font-weight: 600; border-top: 1px solid #cbd5e0; }
        .grand td { font-weight: 700; font-size: .95rem; border-top: 2px solid #2d3748; border-bottom: 3px double #2d3748; }
        .badge { display: inline-block; padding: .15rem .6rem; border-radius: 99px; font-size: .75rem; font-weight: 600; }
        .badge-ok { background: #c6f6d5; color: #22543d; }
        .badge-bad { background: #fed7d7; color: #742a2a; }
        .profit { color: #22543d; }
        .loss { color: #742a2a; }
        .back { font-size: .85rem; margin-bottom: 1rem; display: inline-block; color: #4c51bf; text-decoration: none; }
        @media print { body { background: #fff; padding: 0; } .toolbar, .back { display: none; } .report { box-shadow: none; } }
    </style>
</head>
<body>
<div class="report">
    @unless($pdf)
        <a class="back" href="{{ config('nova.path') }}">&larr; Back to Nova</a>
    @endunless
    @yield('content')
</div>
</body>
</html>
