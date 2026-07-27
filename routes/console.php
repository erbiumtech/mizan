<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// 💡 Laravel 12 ka naya tarika Task Scheduling ke liye
Schedule::call(function () {

    // Mpr folder ki saari files uthayein
    $files = Storage::disk('public')->files('Mpr');
    $now = now()->timestamp;

    foreach ($files as $file) {
        // Sirf Comparison wali temporary files ko target karein
        if (str_contains($file, '_Comparison_')) {

            // File ka modified time check karein
            $lastModified = Storage::disk('public')->lastModified($file);

            // Agar file 3600 seconds (1 ghanta) ya us se zyada purani ho chuki hai
            if ($now - $lastModified >= 3600) {
                // Toh isay delete kar do
                Storage::disk('public')->delete($file);
            }
        }
    }

})->everyMinute();

// Project environment monitoring. Runs every minute and dispatches only the
// environments whose own check_interval_min says they are due, so a per-project
// interval works without a schedule entry per project.
//
// Both of these need `schedule:run` on cron AND a running queue worker
// (QUEUE_CONNECTION defaults to `database`). Without them health_status stays
// null and renders as "unknown" — deliberately never as a green tick.
Schedule::command('projects:check-health')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('projects:check-certificates')
    ->dailyAt('06:00');

// Retention for the check history (Prunable on ProjectEnvironmentCheck).
Schedule::command('tenants:artisan', ['model:prune --model=App\\Models\\ProjectEnvironmentCheck'])
    ->daily();
