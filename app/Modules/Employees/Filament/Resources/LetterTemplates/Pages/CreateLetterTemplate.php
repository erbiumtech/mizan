<?php

namespace App\Modules\Employees\Filament\Resources\LetterTemplates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\LetterTemplates\LetterTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLetterTemplate extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LetterTemplateResource::class;
}
