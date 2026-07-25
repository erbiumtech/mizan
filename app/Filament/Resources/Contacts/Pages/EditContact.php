<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContact extends EditRecord
{
    use InteractsWithCustomFields;

    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
