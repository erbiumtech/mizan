<?php

namespace App\Modules\Campaigns\Filament\Resources\Campaigns\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Campaigns\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CampaignResource::class;
}
