<?php

namespace App\Modules\Campaigns\Filament\Resources\Campaigns\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Campaigns\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
