<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisitRefusals\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\FieldVisitRefusals\FieldVisitRefusalResource;
use Filament\Resources\Pages\ListRecords;

class ListFieldVisitRefusals extends ListRecords
{
    protected static string $resource = FieldVisitRefusalResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('refused-check-ins', 'Refused Check-ins: Help')];
    }
}
