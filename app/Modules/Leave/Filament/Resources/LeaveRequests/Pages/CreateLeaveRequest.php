<?php

namespace App\Modules\Leave\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Leave\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

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
     */
    protected function handleRecordCreation(array $data): Model
    {
        $request = new LeaveRequest($data);

        return app(LeaveRequestService::class)->submit($request, auth()->user());
    }
}
