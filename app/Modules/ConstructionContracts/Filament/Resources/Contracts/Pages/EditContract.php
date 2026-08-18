<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\ContractResource;
use Filament\Resources\Pages\EditRecord;

class EditContract extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-contracts', 'Contracts: Help')];
    }
}
