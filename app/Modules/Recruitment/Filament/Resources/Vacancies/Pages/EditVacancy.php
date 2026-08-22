<?php

namespace App\Modules\Recruitment\Filament\Resources\Vacancies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Recruitment\Filament\Resources\Vacancies\VacancyResource;
use Filament\Resources\Pages\EditRecord;

class EditVacancy extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = VacancyResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
