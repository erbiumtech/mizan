<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\LabourRateResource;
use App\Modules\ConstructionCosting\Services\LabourRateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLabourRate extends CreateRecord
{
    protected static string $resource = LabourRateResource::class;

    /**
     * Through `LabourRateService::set()`, which is what refuses an overlap.
     *
     * The refusal cannot live in the form: two rates in force for the same scope at once leaves nothing saying which
     * one the cost report used, and §7.2's protection is worth nothing if a second row can be typed past it. A queue
     * job or an import would walk straight past a form rule.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(LabourRateService::class)->set(
            [
                'job_id' => $data['job_id'] ?? null,
                'trade_id' => $data['trade_id'] ?? null,
                'worker_id' => $data['worker_id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
            ],
            (float) $data['cost_rate_per_hour'],
            $data['effective_from'],
            [
                'effective_to' => $data['effective_to'] ?? null,
                'overtime_multiplier' => $data['overtime_multiplier'] ?? null,
                'burden_percent' => $data['burden_percent'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
