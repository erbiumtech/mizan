<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisits\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\FieldVisits\FieldVisitResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFieldVisit extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = FieldVisitResource::class;
}
