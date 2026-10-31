<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContacts extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('contacts', 'Contacts: Help'),
            CreateAction::make(),
        ];
    }
}
