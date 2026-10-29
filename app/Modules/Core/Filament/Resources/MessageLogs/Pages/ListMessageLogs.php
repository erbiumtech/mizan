<?php

namespace App\Modules\Core\Filament\Resources\MessageLogs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\MessageLogs\MessageLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMessageLogs extends ListRecords
{
    protected static string $resource = MessageLogResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('message-log', 'Message Log: Help')];
    }
}
