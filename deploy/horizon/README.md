# Horizon on the app server

Horizon runs the queue workers and, being one process, is the thing to keep alive: if it
is not running, no queued notification, payslip PDF or scheduled report is ever done, and
nothing errors — the work simply waits in Redis. The health check **Horizon** says whether
it is up, paused or absent; **Queue** says whether anything at all is consuming the queue.

## Install

1. `sudo cp deploy/horizon/horizon.service /etc/systemd/system/mpr-horizon.service`
2. Edit `WorkingDirectory` (the app root), `User`/`Group` (the PHP-FPM user, so the
   workers can write `storage/`) and the php binary path if they differ.
3. `sudo systemctl daemon-reload && sudo systemctl enable --now mpr-horizon`
4. Verify: `php artisan horizon:status` prints `Horizon is running`, and `health:check`
   shows Horizon green.

`horizon.php`'s `production` environment allows ten worker processes. Redis on the same
box must persist the queue across restarts — `deploy/redis/README.md`.

## Deploys

Workers hold the code they started with. `deploy/deploy.sh` ends with
`php artisan horizon:terminate`: Horizon finishes the jobs in flight, exits, and systemd's
`Restart=always` starts it again on the new release. Nothing to do by hand.

## Day-to-day

- Logs: `journalctl -u mpr-horizon -f`. Job output itself is in `storage/logs/`.
- Pause without stopping (e.g. before a long migration): `php artisan horizon:pause`, then
  `horizon:continue`.
- After a `.env` or `config/horizon.php` change: `sudo systemctl restart mpr-horizon`.
- The dashboard at `/horizon` is gated by `HorizonServiceProvider::gate()`.
