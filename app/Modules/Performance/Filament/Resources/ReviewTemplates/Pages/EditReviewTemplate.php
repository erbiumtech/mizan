<?php

namespace App\Modules\Performance\Filament\Resources\ReviewTemplates\Pages;

use App\Modules\Performance\Filament\Resources\ReviewTemplates\ReviewTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReviewTemplate extends EditRecord
{
    protected static string $resource = ReviewTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
