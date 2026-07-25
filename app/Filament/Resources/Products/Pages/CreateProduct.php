<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use InteractsWithCustomFields;

    protected static string $resource = ProductResource::class;
}
