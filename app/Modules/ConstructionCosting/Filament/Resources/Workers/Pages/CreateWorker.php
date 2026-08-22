<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Workers\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\Workers\WorkerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorker extends CreateRecord
{
    protected static string $resource = WorkerResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
