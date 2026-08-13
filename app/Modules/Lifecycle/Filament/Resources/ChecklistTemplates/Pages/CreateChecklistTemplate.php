<?php

namespace App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\ChecklistTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChecklistTemplate extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ChecklistTemplateResource::class;
}
