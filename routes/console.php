<?php

// Scheduled work belongs to the module that owns it, so each module's
// routes/console.php carries its own entries and its own licence guard:
//
//   app/Modules/Mpr/routes/console.php       comparison-export cleanup
//   app/Modules/Projects/routes/console.php  health, certificates, prune
//
// What is left here is what belongs to no module: the installation's own health.
// It is not licensed, not per-company, and not something a tenant can switch off.

use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

/**
 * Run every check, every minute.
 *
 * Every minute rather than hourly because the two checks that matter most degrade with age:
 * `ScheduleCheck` asks "did cron fire in the last minute", and an unreachable company database
 * discovered an hour late is an hour of a customer seeing errors. The checks are cheap — a PDO
 * connect per company, a Redis ping, a disk stat — and `health:check` writes one history row per
 * check per run, pruned to five days by `keep_history_for_days` in config/health.php.
 *
 * Notifications are throttled to one an hour by that same config, so a minute-by-minute check
 * does not become a minute-by-minute mailbox.
 */
Schedule::command(RunHealthChecksCommand::class)->everyMinute();

/**
 * The heartbeat `ScheduleCheck` reads.
 *
 * This is the one check that cannot detect its own subject: if cron stops, nothing runs to
 * notice that nothing is running. So the heartbeat is written here and read by the check, and a
 * stale heartbeat is the evidence. It follows that **`health:check` failing to run at all is
 * invisible from inside the application** — that is what an external monitor or Oh Dear is for,
 * and why `json_results_failure_status` is 503 rather than 200.
 *
 * Deliberately not `->withoutOverlapping()`: it writes a cache key and returns, and a lock left
 * behind by a killed process would silently stop the heartbeat and report the scheduler dead.
 */
Schedule::command(ScheduleCheckHeartbeatCommand::class)->everyMinute();

/*
 * The heartbeat `QueueCheck` reads — the twin of the one above, one step further along.
 *
 * ScheduleCheck proves cron fires the dispatcher. This pushes a tiny job every minute and QueueCheck fails
 * when it has not been *processed* recently, which proves a worker is on the other end consuming what was
 * dispatched. Without it a dead worker is invisible: the schedule runs, the jobs pile up in Redis, and every
 * notification and PDF in the application quietly stops arriving.
 */
Schedule::command('health:queue-check-heartbeat')->everyMinute();

/*
 * The backups themselves.
 *
 * Everything for restoring this application existed — spatie/laravel-backup configured, `backup:tenants`
 * written for the database-per-company shape the package cannot see, BackupsCheck watching the archive
 * directory — and nothing was on the schedule to *make* one. `backup:list` reported "There are no backups
 * of this application at all." A monitoring check that fails every night is only useful if the thing it
 * monitors was ever meant to run.
 *
 * Three commands, spread out and never overlapping: clean first so the disk has room, then the landlord
 * (`companies`, `users`, roles, uploads), then one archive per company. Order matters for the restore,
 * which needs a landlord archive from the same night as the tenant archive it opens — see
 * deploy/backups/README.md, and the docblock on App\Backup\TenantBackup.
 *
 * `backup:tenants` exits non-zero when any company fails, and that exit code is the only monitoring a stale
 * *tenant* archive has (config/backup.php explains why), so the scheduler's own failure output must reach
 * somebody.
 */
Schedule::command('backup:clean')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:tenants')->dailyAt('02:00')->withoutOverlapping();
