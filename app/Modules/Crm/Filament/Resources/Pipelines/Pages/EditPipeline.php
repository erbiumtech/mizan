<?php

namespace App\Modules\Crm\Filament\Resources\Pipelines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\Pipelines\PipelineResource;
use Filament\Resources\Pages\EditRecord;

class EditPipeline extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
