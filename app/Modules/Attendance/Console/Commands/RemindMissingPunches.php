<?php

namespace App\Modules\Attendance\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Notifications\RecordChanged;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * "You clocked in and never out" — docs/saas-launch-plan.md §14.4. Off by default: a
 * company that marks days by hand would be nagging everybody every evening.
 */
class RemindMissingPunches extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'attendance:remind-missing-punches {--tenant=*} {--date= : The day to check; today by default}';

    protected $description = 'Remind employees who clocked in today and have not clocked out';

    public function handle(): int
    {
        if ($this->skipsDisabledModule('attendance')) {
            return self::SUCCESS;
        }

        if (! (bool) setting('attendance.remind_missing_punches', config('attendance.remind_missing_punches', false))) {
            $this->line('Missing-punch reminders are off for this company.');

            return self::SUCCESS;
        }

        $date = $this->option('date') ?: now()->toDateString();
        $sent = 0;

        foreach (AttendanceDay::query()->with('employee.user')->whereDate('date', $date)->whereNotNull('check_in_at')->whereNull('check_out_at')->get() as $day) {
            $user = $day->employee?->user;

            if ($user === null) {
                continue;
            }

            $user->notify(new RecordChanged('You have not clocked out', 'Your clock-in for '.$day->date->format('d M').' has no clock-out. Clock out, or file a correction if you forgot.'));
            $sent++;
        }

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
