<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\ProgressClaimResource;
use Filament\Resources\Pages\EditRecord;

class EditProgressClaim extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ProgressClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-certificates', 'Claims and certificates: Help')];
    }
}
