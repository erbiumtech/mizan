<?php

namespace App\Console\Commands;

use App\Support\PermissionCache;
use Illuminate\Console\Command;

/**
 * Clear spatie's cached permission list for every company.
 *
 *   php artisan permission:flush-cache
 *
 * Not the same as spatie's own `permission:cache-reset`, which clears the copy belonging to
 * the current context only — and the console has no company, so that command cannot reach any
 * of theirs. See PermissionCache for why each company has its own copy and what a stale one
 * does to the panel.
 */
class FlushPermissionCache extends Command
{
    protected $signature = 'permission:flush-cache';

    protected $description = 'Clear the cached permission list for every company, not just the current context';

    public function handle(): int
    {
        $flushed = PermissionCache::flushEverywhere();

        $this->info('Permission cache cleared for the platform context.');

        if ($flushed === []) {
            $this->line('<fg=gray>No per-company caches to clear (no tenant connection configured).</>');

            return self::SUCCESS;
        }

        $this->line('Cleared for '.count($flushed).' company(ies): '.implode(', ', $flushed));

        return self::SUCCESS;
    }
}
