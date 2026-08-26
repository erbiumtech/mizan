<?php

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Is the backup configured to tell anyone it failed, and is the archive encrypted?
 *
 * `BackupsCheck` already watches whether archives exist and are fresh. Neither it nor anything else
 * watches the two settings that decide what a backup is *worth*, and both fail by being absent rather
 * than by being wrong — which is why they survived to production unnoticed.
 *
 * **The notification address.** `backup.notifications.mail.to` reads `BACKUP_NOTIFICATION_EMAIL` and falls
 * back to `MAIL_FROM_ADDRESS`; with neither set it is null, and spatie/laravel-backup reports that as
 * `invalidEmail(): Argument #1 ($email) must be of type string, null given` — a TypeError from a method
 * whose whole job was to explain the problem, naming nothing about the cause. A production server had
 * exactly this, so `backup:run` aborted and nobody was told, which is the failure mode a backup exists to
 * prevent, relocated.
 *
 * **The archive password.** `BACKUP_ARCHIVE_PASSWORD` unset means `backup.backup.password` is null, and the
 * published config says plainly: "Set to `null` to disable encryption." So the default state of a fresh
 * install is an unencrypted database dump, and nothing anywhere says so. That is a decision worth taking
 * deliberately rather than inheriting from a variable nobody knew to set.
 *
 * **Registered in production only**, following the same reasoning as `DebugModeCheck` and
 * `EnvironmentCheck` beside it: a developer's machine has no reason to encrypt a dump that never leaves it,
 * and two permanent ambers are how a dashboard stops being read. Absent locally rather than green — a check
 * that lies in the reassuring direction is worse than one that is not there.
 */
class BackupConfigurationCheck extends Check
{
    public function run(): Result
    {
        $recipients = array_filter((array) config('backup.notifications.mail.to'));
        $valid = array_filter($recipients, fn ($email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        $password = config('backup.backup.password');
        $encrypted = filled($password) && config('backup.backup.encryption') !== 'none';

        $result = Result::make()->meta([
            'notification_recipients' => count($valid),
            'archive_encrypted' => $encrypted,
        ]);

        $problems = [];

        if ($valid === []) {
            // Failed, not warned: a backup that cannot report its own failure is the one state in which
            // every other signal here is also unreliable.
            $problems[] = $recipients === []
                ? 'no backup notification address is set, so a failed backup will abort with a TypeError and '
                    .'tell nobody — set BACKUP_NOTIFICATION_EMAIL'
                : 'the backup notification address is not a valid email: '.implode(', ', $recipients);
        }

        if (! $encrypted) {
            $problems[] = 'the backup archive is not encrypted — set BACKUP_ARCHIVE_PASSWORD, or record that '
                .'unencrypted archives are intended';
        }

        if ($problems === []) {
            return $result
                ->shortSummary(count($valid).' notified, encrypted')
                ->ok('Backup failures are reported and archives are encrypted.');
        }

        $summary = $valid === [] ? 'unreported' : 'unencrypted';

        // The address being absent is a failure; only the encryption being absent is a warning, because one
        // is a monitoring blind spot and the other is a risk somebody may have accepted.
        return $valid === []
            ? $result->shortSummary($summary)->failed(ucfirst(implode('; ', $problems)).'.')
            : $result->shortSummary($summary)->warning(ucfirst(implode('; ', $problems)).'.');
    }
}
