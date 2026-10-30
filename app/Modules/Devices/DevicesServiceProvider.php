<?php

namespace App\Modules\Devices;

use App\Modules\Devices\Console\Commands\CheckDeviceSilence;
use App\Modules\Devices\Console\Commands\FoldDevicePunches;
use App\Modules\Devices\Filament\Settings\DevicesSettingsSection;
use App\Modules\Devices\Models\AttendanceDevice;
use App\Modules\Devices\Models\DevicePunch;
use App\Modules\Devices\Policies\AttendanceDevicePolicy;
use App\Modules\Devices\Policies\DevicePunchPolicy;
use App\Support\SettingsSections;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DevicesServiceProvider extends ServiceProvider
{
    private const POLICIES = [
        AttendanceDevice::class => AttendanceDevicePolicy::class,
        DevicePunch::class => DevicePunchPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // The push endpoints, public: the serial is the credential — ResolveDeviceTenant.
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        $this->commands([FoldDevicePunches::class, CheckDeviceSilence::class]);

        // 77: with the other public doors, directly before field visits (78).
        SettingsSections::register('devices.devices', DevicesSettingsSection::class, 77, 'people');
    }
}
