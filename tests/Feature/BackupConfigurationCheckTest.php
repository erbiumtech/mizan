<?php

namespace Tests\Feature;

use App\Health\BackupConfigurationCheck;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

/**
 * The two backup settings that fail by being absent.
 *
 * Both reached production unnoticed because neither is wrong when unset — one is null and one is null, and
 * `BackupsCheck` stays green either way because archives were still being written. What they cost is
 * different: an unset notification address means a failed backup aborts with a TypeError and tells nobody,
 * and an unset archive password means the dump is not encrypted.
 */
class BackupConfigurationCheckTest extends TestCase
{
    private function check(): \Spatie\Health\Checks\Result
    {
        return (new BackupConfigurationCheck)->run();
    }

    private function configure(mixed $to, ?string $password, string $encryption = 'default'): void
    {
        config([
            'backup.notifications.mail.to' => $to,
            'backup.backup.password' => $password,
            'backup.backup.encryption' => $encryption,
        ]);
    }

    public function test_a_configured_backup_passes(): void
    {
        $this->configure('ops@example.test', 'a-real-password');

        $result = $this->check();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertTrue($result->meta['archive_encrypted']);
        $this->assertSame(1, $result->meta['notification_recipients']);
    }

    /**
     * The production state, exactly.
     *
     * `BACKUP_NOTIFICATION_EMAIL` unset and `MAIL_FROM_ADDRESS` unset leaves this null, which
     * spatie/laravel-backup reports as "invalidEmail(): Argument #1 ($email) must be of type string, null
     * given" — from the very method meant to explain the problem.
     */
    public function test_no_notification_address_is_a_failure(): void
    {
        $this->configure(null, 'a-real-password');

        $result = $this->check();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('BACKUP_NOTIFICATION_EMAIL', $result->notificationMessage);
        $this->assertSame(0, $result->meta['notification_recipients']);
    }

    public function test_an_address_that_is_not_an_email_is_a_failure(): void
    {
        $this->configure('not-an-email', 'a-real-password');

        $result = $this->check();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('not a valid email', $result->notificationMessage);
    }

    /**
     * A warning rather than a failure: an unencrypted archive may be a risk somebody accepted, while an
     * unreportable failure is a blind spot nobody chose.
     */
    public function test_an_unencrypted_archive_is_a_warning(): void
    {
        $this->configure('ops@example.test', null);

        $result = $this->check();

        $this->assertSame(Status::warning(), $result->status);
        $this->assertStringContainsString('BACKUP_ARCHIVE_PASSWORD', $result->notificationMessage);
        $this->assertFalse($result->meta['archive_encrypted']);
    }

    /** A password with `encryption` switched off is still not encrypted, and must not read as though it is. */
    public function test_a_password_with_encryption_disabled_is_not_encrypted(): void
    {
        $this->configure('ops@example.test', 'a-real-password', 'none');

        $result = $this->check();

        $this->assertSame(Status::warning(), $result->status);
        $this->assertFalse($result->meta['archive_encrypted']);
    }

    /** Both absent reports the failure, since that is the one that makes every other signal unreliable. */
    public function test_both_missing_reports_the_failure(): void
    {
        $this->configure(null, null);

        $result = $this->check();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('BACKUP_NOTIFICATION_EMAIL', $result->notificationMessage);
        $this->assertStringContainsString('BACKUP_ARCHIVE_PASSWORD', $result->notificationMessage);
    }

    /** spatie/laravel-backup allows an array of recipients, so the check has to accept one. */
    public function test_an_array_of_recipients_is_accepted(): void
    {
        $this->configure(['ops@example.test', 'cto@example.test'], 'a-real-password');

        $result = $this->check();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertSame(2, $result->meta['notification_recipients']);
    }
}
