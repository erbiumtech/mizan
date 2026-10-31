<?php

namespace App\Modules\Leave\Filament\Resources\LeaveGroups\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Leave\Filament\Resources\LeaveGroups\LeaveGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeaveGroups extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = LeaveGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leave-groups', 'Leave Groups: Help'),
            CreateAction::make(),
        ];
    }
}
