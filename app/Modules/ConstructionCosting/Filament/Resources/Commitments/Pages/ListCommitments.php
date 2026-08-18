<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Commitments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\CommitmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCommitments extends ListRecords
{
    protected static string $resource = CommitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-commitments', 'Orders: Help'),
            CreateAction::make(),
        ];
    }
}
