<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Commitments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\CommitmentResource;
use Filament\Resources\Pages\EditRecord;

class EditCommitment extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CommitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-commitments', 'Orders: Help')];
    }
}
