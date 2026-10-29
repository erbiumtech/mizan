<?php

namespace App\Modules\Devices\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\Devices\Models\AttendanceDevice;
use App\Modules\Devices\Notifications\DeviceSilent;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * A device quiet for a day — docs/saas-launch-plan.md §8.7. Landlord-side, over
 * every registered device; the administrators are looked up inside the device's
 * company, the way the incident manager finds its fallback role. Told once per
 * silence: the next call from the device clears the mark.
 */
class CheckDeviceSilence extends Command
{
    protected $signature = 'devices:check-silence';

    protected $description = 'Tell administrators about a device that has not called in for a day';

    public function handle(): int
    {
        $told = 0;

        foreach (AttendanceDevice::query()->active()->whereNull('silence_notified_at')->with('company')->get() as $device) {
            if (! $device->isSilent() || $device->company === null) {
                continue;
            }

            $device->company->execute(function () use ($device, &$told): void {
                $role = Role::where('name', 'Administrator')->first();
                $admins = $role ? User::whereHas('roles', fn ($q) => $q->whereKey($role->getKey()))->get() : collect();

                foreach ($admins as $admin) {
                    $admin->notify(new DeviceSilent($device->name, $device->serial_no, $device->last_seen_at));
                    $told++;
                }
            });

            $device->forceFill(['silence_notified_at' => now()])->save();
            $this->warn("{$device->name} ({$device->serial_no}) has been quiet since ".($device->last_seen_at?->toDayDateTimeString() ?? 'it was registered').'.');
        }

        $this->info("{$told} administrator(s) told.");

        return self::SUCCESS;
    }
}
