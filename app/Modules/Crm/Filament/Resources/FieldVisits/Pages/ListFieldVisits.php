<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisits\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\FieldVisits\FieldVisitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFieldVisits extends ListRecords
{
    protected static string $resource = FieldVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('field-visits', 'Field Visits: Help'),
            CreateAction::make(),
        ];
    }
}
