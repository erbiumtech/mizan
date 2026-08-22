<?php

namespace App\Modules\Recruitment\Filament\Resources\Vacancies\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Recruitment\Filament\Resources\Vacancies\VacancyResource;
use Filament\Resources\Pages\ListRecords;

class ListVacancies extends ListRecords
{
    protected static string $resource = VacancyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('vacancies', 'Vacancies: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
