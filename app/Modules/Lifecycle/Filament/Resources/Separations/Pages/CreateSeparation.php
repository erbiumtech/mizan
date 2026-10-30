<?php

namespace App\Modules\Lifecycle\Filament\Resources\Separations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Resources\Separations\SeparationResource;
use App\Modules\Lifecycle\Services\Separations;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateSeparation extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = SeparationResource::class;

    /**
     * Through the service, so the clearance list is built with the record — docs/saas-launch-plan.md §14.5.
     * Its refusals (a separation already open for this person) are a message, not an error page.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(Separations::class)->open(
                Employee::query()->findOrFail($data['employee_id']),
                $data['type'],
                $data['last_working_day'],
                $data['notice_on'] ?? null,
                $data['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }
}
