<?php

namespace App\Modules\Devices;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** No screens of its own: devices are registered from Company Settings, and punches become attendance days. */
class DevicesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'devices';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
