<?php

namespace App\Modules\Crm\Filament\Resources\NextActions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\NextActions\NextActionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNextAction extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = NextActionResource::class;
}
