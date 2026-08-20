<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\MaterialIssueResource;
use Filament\Resources\Pages\EditRecord;

class EditMaterialIssue extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = MaterialIssueResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-material-issues', 'Material issues: Help')];
    }
}
