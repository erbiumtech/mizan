<?php

namespace App\Modules\Campaigns\Filament\Resources\Campaigns\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Campaigns\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\ListRecords;

class ListCampaigns extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('campaigns', 'Campaigns: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
