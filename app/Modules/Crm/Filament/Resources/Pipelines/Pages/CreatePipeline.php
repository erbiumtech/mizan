<?php

namespace App\Modules\Crm\Filament\Resources\Pipelines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\Pipelines\PipelineResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePipeline extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PipelineResource::class;
}
