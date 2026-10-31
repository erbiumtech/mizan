<?php

namespace App\Modules\Performance\Filament\Resources\ScorecardProfiles\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\ScorecardProfiles\ScorecardProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScorecardProfiles extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = ScorecardProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('scorecard-profiles', 'Scorecard Profiles: Help'),
            CreateAction::make(),
        ];
    }
}
