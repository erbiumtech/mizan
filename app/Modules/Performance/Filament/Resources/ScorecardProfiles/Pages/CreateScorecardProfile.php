<?php

namespace App\Modules\Performance\Filament\Resources\ScorecardProfiles\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\ScorecardProfiles\ScorecardProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScorecardProfile extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ScorecardProfileResource::class;
}
