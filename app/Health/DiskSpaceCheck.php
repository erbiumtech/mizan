<?php

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * How full is the volume the application writes to?
 *
 * A replacement for the package's `UsedDiskSpaceCheck`, which crashed on a production server:
 *
 *     The check named `UsedDiskSpace` did not complete. An exception was thrown with this message:
 *     `Spatie\Regex\Exceptions\RegexFailed: Pattern `/(\d*)%/` with subject `` didn't capture a
 *     group named 1`
 *
 * That check runs `df -P .` through Symfony's `Process` and then matches a percentage out of the
 * output. It never looks at the exit code and never looks at stderr, so when the command produces
 * nothing on stdout — no `df` on the PATH, a restricted shell, a Plesk-style chroot such as the
 * `/httpdocs` this happened under — the regex is handed an empty string and throws. The check is
 * marked crashed, the exception is reported on every scheduled run, and the notification says
 * nothing about disks at all. A monitoring tool that fails by shelling out is worse than no
 * monitoring: it is a permanent red that has to be explained to everyone who sees it.
 *
 * So this asks PHP instead. `disk_total_space()` and `disk_free_space()` are `statvfs(2)` behind a
 * function call — no subprocess, no PATH, no shell, nothing to be missing from the image. There is
 * no `df` to parse because there is no `df`.
 *
 * **It measures `storage_path()`, not the working directory.** `df -P .` measured wherever the
 * process happened to be started from, which for a cron entry is not a decision anyone made. The
 * volume that matters is the one holding backups, uploads and PDF temp files, and filling it is
 * what breaks — a backup that half-writes, a payslip that renders to nothing. If that is a
 * separate mount from the code, this is the side worth watching.
 *
 * **The number reads slightly lower than `df` did.** `df`'s capacity column is used over
 * used-plus-available, which excludes the blocks ext4 reserves for root; total-minus-free counts
 * them. A few points on a threshold of 70 or 85 changes nothing, but the two do not agree to the
 * digit and somebody comparing them should know why.
 *
 * **It fails rather than reading 0% when it cannot tell.** `open_basedir` or `disable_functions`
 * can still take these away. Returning zero would be a green tick meaning "no idea", and a check
 * that lies in the reassuring direction is worse than one that is not there — the same reasoning
 * that keeps {@see BackupConfigurationCheck} out of local development.
 */
class DiskSpaceCheck extends Check
{
    protected int $warningThreshold = 70;

    protected int $errorThreshold = 90;

    protected ?string $path = null;

    /** The volume to measure, as any path on it. Defaults to `storage_path()`. */
    public function path(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function warnWhenUsedSpaceIsAbovePercentage(int $percentage): self
    {
        $this->warningThreshold = $percentage;

        return $this;
    }

    public function failWhenUsedSpaceIsAbovePercentage(int $percentage): self
    {
        $this->errorThreshold = $percentage;

        return $this;
    }

    public function run(): Result
    {
        $path = $this->path ?? storage_path();

        [$total, $free] = $this->stat($path);

        if ($total === null || $free === null || $total <= 0.0) {
            return Result::make()
                ->meta(['path' => $path])
                ->shortSummary('unknown')
                ->failed(
                    "Disk usage of {$path} could not be determined: disk_total_space() and "
                    .'disk_free_space() returned nothing. Check open_basedir and disable_functions, '
                    .'and that the path exists and is readable.'
                );
        }

        $used = max(0.0, $total - $free);
        $percentage = (int) round($used / $total * 100);

        $result = Result::make()
            ->meta([
                // Same key the package used, so anything already reading the history keeps working.
                'disk_space_used_percentage' => $percentage,
                'path' => $path,
                'free_bytes' => (int) $free,
                'total_bytes' => (int) $total,
            ])
            ->shortSummary($percentage.'%');

        // The percentage says how worried to be; the bytes say how long there is to act on it.
        $detail = "{$percentage}% used, ".$this->humanize($free).' free of '.$this->humanize($total);

        if ($percentage > $this->errorThreshold) {
            return $result->failed("The disk holding {$path} is almost full ({$detail}).");
        }

        if ($percentage > $this->warningThreshold) {
            return $result->warning("The disk holding {$path} is filling up ({$detail}).");
        }

        return $result->ok("{$detail}.");
    }

    /**
     * Total and free bytes for the volume `$path` sits on, or two nulls if the host will not say.
     *
     * Both `@` and the catch, deliberately. These functions warn and return false on failure, and
     * Laravel's error handler turns warnings into `ErrorException` — so without the `@` a blocked
     * `open_basedir` would crash this check exactly as the shelled-out `df` did, having replaced
     * one unhandled failure with another. The catch covers what `@` does not: a `ValueError` from
     * a path with a null byte in it.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function stat(string $path): array
    {
        try {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
        } catch (Throwable) {
            return [null, null];
        }

        return [
            is_float($total) ? $total : null,
            is_float($free) ? $free : null,
        ];
    }

    /** Bytes as something a person can read, without leaning on ext-intl, which is not required here. */
    private function humanize(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $step = 0;

        while ($bytes >= 1024 && $step < count($units) - 1) {
            $bytes /= 1024;
            $step++;
        }

        return round($bytes, $step === 0 ? 0 : 1).' '.$units[$step];
    }
}
