<?php

namespace App\Modules\Lifecycle\Filament\Resources\Separations\Pages;

use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Resources\Separations\SeparationResource;
use App\Modules\Lifecycle\Services\Separations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSeparation extends CreateRecord
{
    protected static string $resource = SeparationResource::class;

    /** Through the service, so the clearance list is built with the record — docs/saas-launch-plan.md §14.5. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(Separations::class)->open(
            Employee::query()->findOrFail($data['employee_id']),
            $data['type'],
            $data['last_working_day'],
            $data['notice_on'] ?? null,
            $data['reason'] ?? null,
        );
    }
}
