<?php

namespace App\Modules\Recruitment\Filament\Resources\Applicants\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Recruitment\Filament\Resources\Applicants\ApplicantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApplicant extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ApplicantResource::class;
}
