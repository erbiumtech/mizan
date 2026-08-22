<?php

namespace App\Modules\Recruitment\Filament\Resources\Vacancies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Recruitment\Filament\Resources\Vacancies\VacancyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVacancy extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = VacancyResource::class;
}
