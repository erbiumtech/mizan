<?php

namespace App\Modules\Performance\Filament\Resources\ScorecardProfiles\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\ScorecardProfiles\ScorecardProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScorecardProfile extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ScorecardProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ReplicateRecordAction::make()];
    }
}
