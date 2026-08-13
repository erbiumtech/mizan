<?php

namespace App\Modules\Crm\Filament\Resources\Opportunities\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\Opportunities\OpportunityResource;
use Filament\Resources\Pages\EditRecord;

class EditOpportunity extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = OpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
