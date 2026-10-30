<?php

namespace App\Modules\Payroll\Filament\Resources\PayAdjustments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\PayAdjustments\PayAdjustmentResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreatePayAdjustment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PayAdjustmentResource::class;

    /**
     * The model refuses an adjustment for a signed-off month; said here rather than as an error page.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }
}
