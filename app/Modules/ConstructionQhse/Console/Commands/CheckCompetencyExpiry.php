<?php

namespace App\Modules\ConstructionQhse\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\ConstructionQhse\Notifications\CompetencyExpiring;
use App\Modules\ConstructionQhse\Services\SitePersonnelService;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * The daily competency clock — §17.5.
 *
 * Shaped on `CheckDelayNotices` and `CheckComplianceExpiry`, which solved the same problem twice already: once per
 * threshold rather than once per day, marked whether or not anybody was notified, and **named rather than counted** in
 * the console output — "4 tickets expiring" sends somebody hunting through a register.
 *
 * Daily rather than weekly for the reason both give: the tightest threshold is the day itself, and a weekly run would
 * step straight over it. Somebody would go from "expires in six days" to working with a lapsed ticket with nothing sent
 * in between, which is the case the warning exists for.
 */
class CheckCompetencyExpiry extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'construction:check-competency-expiry
                            {--date= : Treat this date as today}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Warn before a ticket on the site personnel register runs out';

    public function handle(SitePersonnelService $personnel): int
    {
        if ($this->skipsDisabledModule('construction_qhse')) {
            return self::SUCCESS;
        }

        $due = $personnel->dueForWarning($this->option('date'));

        if ($due->isEmpty()) {
            $this->info('No competency has newly crossed an expiry warning threshold.');

            return self::SUCCESS;
        }

        /*
         * Whoever maintains the register, which is whoever inducts people — `ConstructionPersonnelUpdate`. Not the
         * permit issuer: a lapsed ticket is fixed by renewing it or moving somebody, and both are the register's own
         * work. The same call `CheckDelayNotices` and `CheckComplianceExpiry` both make.
         */
        $recipients = User::holdingPermission('ConstructionPersonnelUpdate')->where('status', 1)->get();

        foreach ($due as $entry) {
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new CompetencyExpiring($entry['competency'], (int) $entry['competency']->daysUntilExpiry($this->option('date'))));
            }

            /*
             * Marked whether or not anybody was notified. A company with nobody holding the permission would otherwise
             * re-report the same ticket every night for ever, and the backlog would bury the first real one.
             */
            $personnel->markWarned($entry['competency'], $entry['threshold']);
        }

        // Named, not counted, and the mandatory ones said so — those are the ones that stop somebody working.
        $this->info('Warned about: '.$due->map(function (array $entry): string {
            $competency = $entry['competency'];
            $days = (int) $competency->daysUntilExpiry($this->option('date'));

            return ($competency->sitePersonnel?->name ?? 'unknown').' — '.$competency->title
                .($competency->is_mandatory ? ' [mandatory]' : '')
                .' ('.($days < 0 ? abs($days).'d lapsed' : $days.'d left').')';
        })->implode(', ').'.');

        return self::SUCCESS;
    }
}
