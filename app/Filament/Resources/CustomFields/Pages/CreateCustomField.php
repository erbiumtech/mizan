<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomField extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CustomFieldResource::class;
}
