<?php

namespace App\Modules\Core\Filament\Resources\OptionValues\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\OptionValues\OptionValueResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOptionValue extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = OptionValueResource::class;
}
