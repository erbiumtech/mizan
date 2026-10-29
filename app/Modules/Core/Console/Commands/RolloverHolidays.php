<?php

namespace App\Modules\Core\Console\Commands;

use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Services\HolidayCalendar;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Next year's recurring holidays, offered — docs/saas-launch-plan.md §14.4. A fixed-date
 * holiday (Independence Day) rolls to the same day; a moving one (Eid) rolls too, and the
 * row's note says the date is somebody's to confirm, as the form already warns.
 */
class RolloverHolidays extends Command
{
    use TenantAware;

    protected $signature = 'holidays:rollover {--year= : The year to create; next year by default} {--tenant=*}';

    protected $description = 'Copy every recurring holiday into the next year where it is not already there';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now()->addYear()->year);
        $made = 0;

        foreach (Holiday::query()->where('is_recurring', true)->orderBy('date')->get() as $holiday) {
            $date = $holiday->date->copy()->year($year);

            if (Holiday::query()->whereDate('date', $date->toDateString())->exists()) {
                continue;
            }

            Holiday::create([
                'date' => $date->toDateString(),
                'name' => $holiday->name,
                'kind' => $holiday->kind,
                'location_id' => $holiday->location_id,
                'is_recurring' => true,
                'notes' => trim(($holiday->notes ? $holiday->notes.' ' : '').'Rolled over from '.$holiday->date->year.'; confirm the date.'),
            ]);
            $made++;
        }

        app(HolidayCalendar::class)->flush();
        $this->info("{$made} holiday(s) created for {$year}.");

        return self::SUCCESS;
    }
}
