<?php

namespace App\Modules\ConstructionContracts\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\ConstructionContracts\Notifications\ComplianceDocumentExpiring;
use App\Modules\ConstructionContracts\Services\ComplianceService;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Warn before a subcontractor's cover lapses — `docs/construction-management-plan.md` §12.
 *
 * **The warning exists so that the refusal never has to happen.** §12's block at certification is the right place for
 * the rule, but a first warning delivered as a refused certificate on payment-run day is a warning delivered too late:
 * the work is done, the claim is in, and the only options left are to chase an insurer overnight or to override. Sixty
 * days of notice turns that into an email.
 *
 * Once per threshold crossed, never once per day — `ComplianceService::dueForWarning()` holds that rule and
 * `DocumentExpiryCheck` explains why it is worth the column: "a daily job that mails the same person the same warning
 * for thirty days trains them to filter it, and then the one that mattered is filtered too".
 */
class CheckComplianceExpiry extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'construction:check-compliance
                            {--date= : Treat this date as today}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Warn about subcontractor insurance and licences before they lapse and stop a certificate';

    public function handle(ComplianceService $compliance): int
    {
        if ($this->skipsDisabledModule('construction_contracts')) {
            return self::SUCCESS;
        }

        $due = $compliance->dueForWarning($this->option('date'));

        if ($due->isEmpty()) {
            $this->info('No compliance document has newly crossed a warning threshold.');

            return self::SUCCESS;
        }

        /*
         * Whoever maintains the register, not whoever certifies. The person who can chase an insurer is the person who
         * files the certificates, and mailing the approver would put the warning in front of somebody whose only
         * available action is the override.
         */
        $recipients = User::holdingPermission('ConstructionComplianceUpdate')->where('status', 1)->get();

        foreach ($due as $entry) {
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ComplianceDocumentExpiring($entry['document'], $entry['days']));
            }

            /*
             * Marked whether or not anybody was notified. A company with nobody holding the permission would otherwise
             * re-report the same document every night for ever, and the backlog would bury the first real one —
             * `CheckDocumentExpiry` made the same call for the same reason.
             */
            $compliance->markWarned($entry['document'], $entry['threshold']);
        }

        // Named, not counted: "4 documents expiring" sends somebody hunting.
        $this->info('Warned about: '.$due->map(
            fn (array $entry): string => $entry['document']->label()
                .' ('.($entry['document']->contract?->contract_number ?? 'company-wide').')'
        )->implode(', ').'.');

        return self::SUCCESS;
    }
}
