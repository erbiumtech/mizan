<?php

namespace App\Modules\Employees\Filament\Resources\LetterTemplates\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\LetterTemplates\LetterTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLetterTemplates extends ListRecords
{
    protected static string $resource = LetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('letter-templates', 'Letter Templates: Help'),
            CreateAction::make(),
        ];
    }
}
