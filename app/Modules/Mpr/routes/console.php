<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// 💡 Laravel 12 ka naya tarika Task Scheduling ke liye
// Sweeps the temporary MPR comparison exports out of the public disk once they
// are an hour old.
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
