<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Pages;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\PlantLogResource;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Services\PlantService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlantLog extends CreateRecord
{
    protected static string $resource = PlantLogResource::class;

    /**
     * Through `PlantService::log()`, which holds the three guards.
     *
     * A heading cost code, a log of no units at all, and — the one worth the round trip — a closing meter reading lower
     * than the opening one, which a meter cannot do and which is either a typo or a replaced instrument.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $item = PlantItem::query()->findOrFail($data['plant_item_id']);
        $job = Job::query()->findOrFail($data['job_id']);
        $code = CostCode::query()->findOrFail($data['cost_code_id']);

        unset($data['plant_item_id'], $data['job_id'], $data['cost_code_id']);

        return app(PlantService::class)->log($item, $job, $code, array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '',
        ));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
