<?php

namespace App\Modules\Recruitment\Filament\Resources\Applicants\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Recruitment\Filament\Resources\Applicants\ApplicantResource;
use Filament\Resources\Pages\ListRecords;

class ListApplicants extends ListRecords
{
    protected static string $resource = ApplicantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('applicants', 'Applicants: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
