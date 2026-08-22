<?php

namespace App\Modules\Crm\Filament\Resources\LeadSources\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\LeadSources\LeadSourceResource;
use Filament\Resources\Pages\EditRecord;

class EditLeadSource extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeadSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
