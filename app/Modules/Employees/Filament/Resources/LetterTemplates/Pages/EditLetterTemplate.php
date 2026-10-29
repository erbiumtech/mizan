<?php

namespace App\Modules\Employees\Filament\Resources\LetterTemplates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\LetterTemplates\LetterTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLetterTemplate extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
