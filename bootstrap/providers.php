<?php

use App\Modules\Mpr\MprServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,

    // One per module physically moved into app/Modules. Each carries its own
    // policies and routes; its Filament classes are registered by the matching
    // plugin listed in config/modules.php.
    MprServiceProvider::class,
];
