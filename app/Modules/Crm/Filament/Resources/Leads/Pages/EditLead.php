<?php

namespace App\Modules\Crm\Filament\Resources\Leads\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\Leads\LeadResource;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
