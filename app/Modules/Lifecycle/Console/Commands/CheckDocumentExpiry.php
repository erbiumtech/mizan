<?php

namespace App\Modules\Lifecycle\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Notifications\DocumentExpiring;
use App\Modules\Lifecycle\Services\DocumentExpiryCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Warn about documents about to lapse — once per threshold, not once per day.
 *
 * The same shape CheckEnvironmentCertificates already proves out, and for the same
 * reason it was written that way there: notifications on transitions. The threshold
 * last warned at is recorded on the document, so crossing 60 days warns once, crossing
 * 30 warns again, and the twenty-nine days in between are silent.
 */
class CheckDocumentExpiry extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'lifecycle:check-documents {--tenant=*} {--date= : Treat this date as today}';

    protected $description = 'Warn whoever manages documents that one is about to lapse, once per threshold';

    public function handle(DocumentExpiryCheck $check): int
    {
        if ($this->skipsDisabledModule('lifecycle')) {
            return self::SUCCESS;
        }

        $due = $check->due($this->option('date'));

        if ($due->isEmpty()) {
            $this->info('No documents have newly crossed a warning threshold.');

            return self::SUCCESS;
        }

        $recipients = User::holdingPermission('EmployeeDocumentUpdate')->where('status', 1)->get();

        foreach ($due as $entry) {
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new DocumentExpiring($entry['document'], $entry['days']));
            }

            // Marked whether or not anybody was notified. A company with nobody holding
            // the permission would otherwise re-report the same document every night
            // for ever, and the backlog would bury the first real one.
            $check->markNotified($entry['document'], $entry['threshold']);
        }

        // Named, not counted: "4 documents expiring" sends somebody hunting.
        $this->info('Warned about: '.$due->map(
            fn (array $entry): string => $entry['document']->kind.' for '
                .($entry['document']->employee?->employee_id ?? 'unknown')
        )->implode(', ').'.');

        return self::SUCCESS;
    }
}
