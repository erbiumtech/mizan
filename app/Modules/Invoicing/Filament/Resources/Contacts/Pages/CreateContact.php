<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContact extends CreateRecord
{
    use InteractsWithCustomFields, RedirectsToIndex;

    protected static string $resource = ContactResource::class;
}
