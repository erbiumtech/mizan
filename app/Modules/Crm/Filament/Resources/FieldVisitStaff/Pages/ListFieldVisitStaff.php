<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisitStaff\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\FieldVisitStaff\FieldVisitStaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFieldVisitStaff extends ListRecords
{
    protected static string $resource = FieldVisitStaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('field-visit-staff', 'Field Visit Staff: Help'),
            CreateAction::make()->label('Enrol somebody'),
        ];
    }
}
