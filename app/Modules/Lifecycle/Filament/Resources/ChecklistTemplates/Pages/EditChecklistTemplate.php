<?php

namespace App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\ChecklistTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditChecklistTemplate extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ChecklistTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
