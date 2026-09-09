<?php

namespace App\Health;

use Spatie\Health\Checks\Checks\HorizonCheck as PackageHorizonCheck;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Is Horizon supervising the workers? Answered without crashing when it cannot be reached.
 *
 * The package's check catches only the container resolution, so the very next line —
 * `MasterSupervisorRepository::all()`, which is a Redis read — throws straight out of `run()`:
 *
 *     The check named `Horizon` did not complete. An exception was thrown with this message:
 *     `RedisException: Connection refused`
 *
 * A production server without Redis running logged that with a full stack trace **once a minute,
 * 800 times in thirteen hours**, which is most of what was in the log file and is how a log stops
 * being read. Worse, a crashed check is not a *failed* check: `health:check` exits non-zero and
 * the notification talks about the check rather than about Horizon, so the one fact worth sending
 * — nothing is consuming the queue — is the fact that goes missing.
 *
 * So an unreachable Horizon is reported as failed, naming the connection it could not reach. Same
 * red tile, same notification, one line instead of forty. Everything else is the package's own
 * answer, including the paused-versus-stopped distinction that is the reason to use this check at
 * all.
 *
 * Registered only where Horizon is the intended supervisor — see AppServiceProvider. An
 * installation whose queue is not Redis has no Horizon to watch, and a permanent red that is
 * nobody's problem is the other way a dashboard stops being read.
 */
class HorizonCheck extends PackageHorizonCheck
{
    public function run(): Result
    {
        try {
            return parent::run();
        } catch (Throwable $exception) {
            return Result::make()
                ->meta(['connection' => $this->connection(), 'error' => $exception->getMessage()])
                ->shortSummary('Unreachable')
                ->failed(sprintf(
                    'Horizon could not be reached at %s (%s), so nothing is known to be consuming the '
                    .'queue. Start Redis and the Horizon service — see deploy/horizon/README.md.',
                    $this->connection(),
                    $exception->getMessage(),
                ));
        }
    }

    /** The Redis connection Horizon reads, as host:port, for a message somebody has to act on. */
    protected function connection(): string
    {
        $name = (string) config('horizon.use', 'default');
        $connection = (array) config("database.redis.{$name}", []);

        return isset($connection['host'])
            ? $connection['host'].':'.($connection['port'] ?? 6379)
            : "redis connection [{$name}]";
    }
}
