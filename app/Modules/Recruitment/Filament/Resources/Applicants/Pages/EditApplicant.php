<?php

namespace App\Modules\Recruitment\Filament\Resources\Applicants\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Recruitment\Filament\Resources\Applicants\ApplicantResource;
use Filament\Resources\Pages\EditRecord;

class EditApplicant extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ApplicantResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
