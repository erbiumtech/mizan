<?php

namespace App\Modules\Employees\Filament\Resources\LetterRequests\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\LetterRequests\LetterRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLetterRequests extends ListRecords
{
    protected static string $resource = LetterRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('letter-requests', 'Letter Requests: Help'),
            CreateAction::make(),
        ];
    }
}
