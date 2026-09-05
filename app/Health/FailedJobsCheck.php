<?php

namespace App\Health;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * How many jobs have failed and been left there.
 *
 * `failed_jobs` is where a notification that could not be sent, a PDF that could not render or a report
 * that could not be delivered ends up — and nothing in the panel shows the table. Six broadcast failures
 * sat in it for a day before anybody looked, and a production without Reverb would have added one per
 * notification for ever. A row here is a fact somebody should read; a growing count is a fault nobody is.
 *
 * Warns at the first row and fails at a threshold, both fluent: one failed job is worth a glance, a
 * hundred is a queue that has been broken for a while.
 */
class FailedJobsCheck extends Check
{
    protected int $warnAt = 1;

    protected int $failAt = 25;

    public function warnWhenCountIsAtLeast(int $count): static
    {
        $this->warnAt = $count;

        return $this;
    }

    public function failWhenCountIsAtLeast(int $count): static
    {
        $this->failAt = $count;

        return $this;
    }

    public function run(): Result
    {
        try {
            $count = (int) DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable $e) {
            return Result::make()->failed('Could not read the failed jobs table: '.$e->getMessage());
        }

        $result = Result::make()->meta(['count' => $count])->shortSummary($count.' failed');

        if ($count >= $this->failAt) {
            return $result->failed("{$count} failed jobs are waiting. Run `queue:failed` to see them, `queue:retry all` once the cause is fixed.");
        }

        if ($count >= $this->warnAt) {
            return $result->warning("{$count} failed job(s) waiting to be read — `queue:failed`.");
        }

        return $result->ok('No failed jobs.');
    }
}
