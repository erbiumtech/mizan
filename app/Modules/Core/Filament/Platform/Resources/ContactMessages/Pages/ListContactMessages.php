<?php

namespace App\Modules\Core\Filament\Platform\Resources\ContactMessages\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Platform\Resources\ContactMessages\ContactMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListContactMessages extends ListRecords
{
    protected static string $resource = ContactMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('contact-messages', 'Contact Messages: Help'),
        ];
    }
}
