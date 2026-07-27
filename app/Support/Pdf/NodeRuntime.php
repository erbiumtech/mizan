<?php

namespace App\Support\Pdf;

use Symfony\Component\Process\Process;

/**
 * Detects whether a usable Node binary exists, so PDF rendering can fall back
 * to a pure-PHP engine on servers without Node installed.
 */
class NodeRuntime
{
    protected static ?bool $available = null;

    public static function isAvailable(): bool
    {
        return static::$available ??= static::probe();
    }

    /** Reset the memoised result (tests). */
    public static function flush(): void
    {
        static::$available = null;
    }

    protected static function probe(): bool
    {
        $binary = (string) config('services.node.binary', 'node');

        if ($binary === '') {
            return false;
        }

        // An absolute path we were handed but which is not there is a clear no.
        if (str_contains($binary, DIRECTORY_SEPARATOR) && ! is_executable($binary)) {
            return false;
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
