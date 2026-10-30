<?php

namespace App\Modules\Leave\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Leave\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EditLeaveRequest extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }

    /**
     * Through the service, like filing: the edit is checked as the request was, and what it
     * costs is worked out again. Saved straight from the form it was neither.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(LeaveRequestService::class)->resubmit($record->fill($data));
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }
}
