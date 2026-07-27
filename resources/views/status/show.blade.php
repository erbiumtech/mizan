{{--
    Public status page. Only the fields prepared by StatusPageController reach
    this template — no URLs, no credentials, no error text.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $companyName }} · Service status</title>
    <style>
        :root { color-scheme: light dark; --bg:#f8fafc; --card:#fff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#0f172a; --card:#1e293b; --text:#e2e8f0; --muted:#94a3b8; --line:#334155; }
        }
        * { box-sizing: border-box; }
        body { margin:0; padding:2rem 1rem; background:var(--bg); color:var(--text);
               font:16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .wrap { max-width:52rem; margin:0 auto; }
        h1 { font-size:1.5rem; margin:0 0 .25rem; }
        .muted { color:var(--muted); font-size:.875rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:.75rem;
                padding:1rem 1.25rem; margin:1rem 0; }
        .row { display:flex; align-items:center; justify-content:space-between; gap:1rem;
               padding:.5rem 0; border-top:1px solid var(--line); flex-wrap:wrap; }
        .row:first-of-type { border-top:0; }
        .name { font-weight:600; }
        .badge { font-size:.75rem; font-weight:600; padding:.15rem .5rem; border-radius:999px;
                 text-transform:uppercase; letter-spacing:.03em; }
        .up { background:#dcfce7; color:#166534; }
        .down { background:#fee2e2; color:#991b1b; }
        .unknown { background:#e2e8f0; color:#475569; }
        @media (prefers-color-scheme: dark) {
            .up { background:#14532d; color:#bbf7d0; }
            .down { background:#7f1d1d; color:#fecaca; }
            .unknown { background:#334155; color:#cbd5e1; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>{{ $companyName }} — service status</h1>
        <p class="muted">Uptime shown over the last {{ $uptimeDays }} days. Updated {{ $generatedAt }}.</p>

        @forelse($projects as $project)
            <div class="card">
                <div class="name" style="margin-bottom:.5rem">{{ $project['name'] }}</div>

                @foreach($project['environments'] as $environment)
                    <div class="row">
                        <span>{{ $environment['label'] }}</span>
                        <span>
                            <span class="muted" style="margin-right:.75rem">
                                {{ $environment['uptime'] === null ? 'no data' : number_format($environment['uptime'], 2).'% uptime' }}
                            </span>
                            <span class="badge {{ $environment['status'] }}">
                                {{ $environment['status'] === 'up' ? 'operational' : ($environment['status'] === 'down' ? 'outage' : 'unknown') }}
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="card muted">No services are published on this status page.</div>
        @endforelse
    </div>
</body>
</html>
