<?php

namespace App\Health;

use Illuminate\Support\Facades\Redis;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Would the queue survive a Redis restart?
 *
 * `QUEUE_CONNECTION=redis`, so every queued payslip notification, PDF and scheduled report lives in Redis
 * until a worker takes it. Redis keeps that in memory; whether it also keeps it on disk is a server
 * setting — AOF (`appendonly yes`) or RDB snapshots (`save ...`) — and with neither, a restart empties the
 * queue with no error anywhere. `RedisCheck` says Redis is up; this says whether up-again means intact.
 *
 * Parsed from `INFO persistence` and `CONFIG GET save` rather than from a config file the application
 * cannot see. Skipped when the queue is not on Redis at all — see `registerHealthChecks()`.
 */
class RedisPersistenceCheck extends Check
{
    public function run(): Result
    {
        try {
            $persistence = self::flatten(Redis::connection()->command('INFO', ['persistence']));
            $save = self::flatten(Redis::connection()->command('CONFIG', ['GET', 'save']));
        } catch (Throwable $e) {
            return Result::make()->failed('Could not ask Redis about persistence: '.$e->getMessage());
        }

        return self::judge($persistence, $save);
    }

    /**
     * The decision, separated from the two Redis calls so a test can hand it canned answers.
     *
     * @param  array<string, mixed>  $persistence  the `INFO persistence` section, flattened
     * @param  array<string, mixed>  $save  `CONFIG GET save`, flattened to ['save' => '3600 1 300 100']
     */
    public static function judge(array $persistence, array $save): Result
    {
        $aof = (string) ($persistence['aof_enabled'] ?? '0') === '1';
        $rdb = trim((string) ($save['save'] ?? '')) !== '';

        $result = Result::make()->meta(['aof' => $aof, 'rdb' => $rdb]);

        if ($aof) {
            return $result->shortSummary('AOF')->ok('Append-only file is on: a restart replays the queue.');
        }

        if ($rdb) {
            return $result->shortSummary('RDB only')->warning(
                'Only RDB snapshots are on (`save '.trim((string) $save['save']).'`). A restart loses whatever '
                .'was queued since the last snapshot. `appendonly yes` in redis.conf closes that gap — see '
                .'deploy/redis/README.md.'
            );
        }

        return $result->shortSummary('none')->failed(
            'Redis has no persistence configured: a restart empties the queue, and every queued notification, '
            .'PDF and scheduled report in it is gone without an error. Set `appendonly yes` — deploy/redis/README.md.'
        );
    }

    /**
     * Redis clients disagree about shape: predis returns `INFO` as nested arrays keyed by section and
     * `CONFIG GET` as either a map or a [key, value] list. One flat map is what the decision reads.
     *
     * @return array<string, mixed>
     */
    public static function flatten(mixed $reply): array
    {
        if (is_string($reply)) {
            $out = [];

            foreach (preg_split('/\r?\n/', $reply) ?: [] as $line) {
                if (str_contains($line, ':') && ! str_starts_with($line, '#')) {
                    [$k, $v] = explode(':', $line, 2);
                    $out[trim($k)] = trim($v);
                }
            }

            return $out;
        }

        if (! is_array($reply)) {
            return [];
        }

        // A [key, value, key, value] list, as CONFIG GET comes back from some clients.
        if (array_is_list($reply) && count($reply) % 2 === 0 && count($reply) > 0 && is_string($reply[0])) {
            $out = [];

            for ($i = 0; $i < count($reply); $i += 2) {
                $out[(string) $reply[$i]] = $reply[$i + 1];
            }

            return $out;
        }

        $out = [];

        foreach ($reply as $k => $v) {
            if (is_array($v)) {
                $out = [...$out, ...self::flatten($v)];
            } else {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }
}
