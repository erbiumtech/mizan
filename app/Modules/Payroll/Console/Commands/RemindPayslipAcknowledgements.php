<?php

namespace App\Modules\Payroll\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Payroll\Models\Payslip;
use App\Notifications\RecordChanged;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * "Please acknowledge your payslip" — docs/saas-launch-plan.md §14.1: at most
 * three, a week apart, then silence. A fourth reminder is nagging, and a
 * payslip nobody acknowledged after three is a conversation, not a message.
 */
class RemindPayslipAcknowledgements extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    public const MAX_REMINDERS = 3;

    protected $signature = 'payroll:remind-acknowledgements {--tenant=*}';

    protected $description = 'Remind employees of payslips sent to them and not yet acknowledged, at most three times';

    public function handle(): int
    {
        if ($this->skipsDisabledModule('payroll')) {
            return self::SUCCESS;
        }

        $due = Payslip::query()
            ->with('employee.user')
            ->whereNotNull('sent_at')
            ->where('employee_review', Payslip::REVIEW_PENDING)
            ->where('acknowledgement_reminders', '<', self::MAX_REMINDERS)
            ->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', now()->subDays(7)))
            ->where('sent_at', '<=', now()->subDays(3))
            ->get();

        $sent = 0;

        foreach ($due as $payslip) {
            $user = $payslip->employee?->user;

            if ($user === null) {
                continue;
            }

            $user->notify(new RecordChanged(
                "Please acknowledge your payslip for {$payslip->month}",
                'Open it, check the figures, and accept it or say what is wrong. Reminder '.($payslip->acknowledgement_reminders + 1).' of '.self::MAX_REMINDERS.'.',
            ));

            $payslip->forceFill(['acknowledgement_reminders' => $payslip->acknowledgement_reminders + 1, 'last_reminded_at' => now()])->saveQuietly();
            $sent++;
        }

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
