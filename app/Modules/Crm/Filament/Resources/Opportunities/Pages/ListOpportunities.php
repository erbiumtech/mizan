<?php

namespace App\Modules\Crm\Filament\Resources\Opportunities\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\Opportunities\OpportunityResource;
use Filament\Resources\Pages\ListRecords;

class ListOpportunities extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = OpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('opportunities', 'Deals: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
