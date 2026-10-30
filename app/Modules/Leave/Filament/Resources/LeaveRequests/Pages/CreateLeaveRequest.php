<?php

namespace App\Modules\Leave\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Leave\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateLeaveRequest extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeaveRequestResource::class;

    /**
     * Filed through the service, not saved straight from the form.
     *
     * The service is what checks notice, refuses an overlap with leave already
     * requested, works out what the range will cost and tells the approvers. Saving
     * the model directly would skip all four — and the API and the importer go
     * through the same method, so there is one answer rather than three.
     *
     * Its refusals — no working days, an overlap, a type this person may not take — are a
     * message on the form, not an error page.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(LeaveRequestService::class)->submit(new LeaveRequest($data), auth()->user());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }
}
