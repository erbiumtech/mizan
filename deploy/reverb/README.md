# Reverb VPS

Laravel Reverb (the WebSocket server behind real-time notifications) runs on
its own small VPS, separate from the app's main hosting — see
`docs/realtime-notifications-plan.md` for why. This box needs enough of the
codebase to run `artisan reverb:start`; it does **not** need a database
connection, migrations, or a queue worker — channel authorization
(`/broadcasting/auth`) happens on the main app server, not here.

## Setup checklist

1. Point DNS for the WS hostname (e.g. `ws.mpr.example`) at this VPS.
2. Deploy a copy of this codebase here (`git pull` + `composer install
   --no-dev --optimize-autoloader` is enough — no `npm install`, no
   `artisan migrate`).
3. Copy `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` from the
   main app's `.env` into this box's `.env` — they must match exactly, since
   they're what signs/verifies channel subscriptions between the two.
4. Install `reverb.service` (`sudo cp reverb.service
   /etc/systemd/system/mpr-reverb.service`), then `systemctl daemon-reload &&
   systemctl enable --now mpr-reverb`.
5. Install `nginx.conf` as a site (adjust `server_name` and the cert paths),
   provision the TLS cert (e.g. `certbot --nginx`), then reload nginx.
6. On the main app server, set `REVERB_HOST` / `VITE_REVERB_HOST` to this
   box's public hostname, `REVERB_PORT=443`, `REVERB_SCHEME=https` (and the
   matching `VITE_REVERB_*` vars), then `php artisan config:clear`.
7. Verify: open the admin panel in a browser, check the Network/WS tab for a
   `wss://` connection to the new host and a successful `/broadcasting/auth`
   POST back to the main app. Or from the CLI: `wscat -c
   wss://ws.mpr.example/app/<REVERB_APP_KEY>`.

## Day-to-day

- Logs: `journalctl -u mpr-reverb -f`.
- Restart after a `REVERB_APP_*` change: `systemctl restart mpr-reverb`.
- `systemd`'s `Restart=always` covers crashes and reboots; no supervisor
  needed on top of it.
