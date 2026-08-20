<?php

namespace App\Modules\ConstructionField\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\ConstructionField\Notifications\DelayNoticeDue;
use App\Modules\ConstructionField\Services\DelayEventService;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * The nightly notice clock — §13, and the piece Phase 9 builds before anything else.
 *
 * *"A valid claim lost to a missed notice is the single most common way a contractor donates money, and it fails in
 * absolute silence."* This command is what breaks the silence.
 *
 * Shaped on `CheckComplianceExpiry`, which solved the same problem for insurance: once per threshold rather than once
 * per day, marked whether or not anybody was notified, and named rather than counted in the console output.
 */
class CheckDelayNotices extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'construction:check-delay-notices
                            {--date= : Treat this date as today}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Warn before a delay event\'s notice period runs out and the claim is lost';

    public function handle(DelayEventService $events): int
    {
        if ($this->skipsDisabledModule('construction_field')) {
            return self::SUCCESS;
        }

        $due = $events->dueForWarning($this->option('date'));

        if ($due->isEmpty()) {
            $this->info('No delay event has newly crossed a notice warning threshold.');

            return self::SUCCESS;
        }

        /*
         * Whoever can serve the notice, which is whoever maintains the register — `ConstructionDelayUpdate`. Not the
         * determiner: §13's clock is about getting a letter out, and mailing the person who assesses claims offers them
         * nothing they can do. The same call `CheckComplianceExpiry` makes, and for the same reason.
         */
        $recipients = User::holdingPermission('ConstructionDelayUpdate')->where('status', 1)->get();

        foreach ($due as $entry) {
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new DelayNoticeDue($entry['event'], $entry['days']));
            }

            /*
             * Marked whether or not anybody was notified. A company with nobody holding the permission would otherwise
             * re-report the same event every night for ever, and the backlog would bury the first real one.
             */
            $events->markWarned($entry['event'], $entry['threshold']);
        }

        // Named, not counted: "4 notices due" sends somebody hunting through a register.
        $this->info('Warned about: '.$due->map(
            fn (array $entry): string => $entry['event']->reference
                .' ('.($entry['event']->job?->code ?? 'no job').', '
                .($entry['days'] < 0 ? abs($entry['days']).'d overdue' : $entry['days'].'d left').')'
        )->implode(', ').'.');

        return self::SUCCESS;
    }
}
