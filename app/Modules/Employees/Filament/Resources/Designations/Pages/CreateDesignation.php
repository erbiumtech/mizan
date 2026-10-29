<?php

namespace App\Modules\Employees\Filament\Resources\Designations\Pages;

use App\Modules\Employees\Filament\Resources\Designations\DesignationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDesignation extends CreateRecord
{
    protected static string $resource = DesignationResource::class;
}
