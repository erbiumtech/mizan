<?php

namespace App\Modules\Core\Filament\Platform\Resources\Users\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Users\PlatformUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlatformUser extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PlatformUserResource::class;
}
