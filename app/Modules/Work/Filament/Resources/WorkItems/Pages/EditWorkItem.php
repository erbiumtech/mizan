<?php

namespace App\Modules\Work\Filament\Resources\WorkItems\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Models\WorkStatus;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EditWorkItem extends EditRecord
{
    protected static string $resource = WorkItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            HelpAction::make('work-items', 'Work Items: Help'),
        ];
    }

    /**
     * A status changed on this form is still a transition.
     *
     * The form is one of four things that can move an item — the board, the API and a merged pull
     * request are the others — and all four go through `moveTo()`, which is what makes `started_at`
     * and `resolved_at` worth computing cycle time from. Saving the column directly here would be a
     * fifth door into the one thing §4.2 says has only one.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $target = $data['work_status_id'] ?? null;
        unset($data['work_status_id']);

        $record->fill($data);

        if ($target !== null && (int) $target !== (int) $record->work_status_id) {
            $status = WorkStatus::query()->findOrFail($target);

            try {
                $record->save();
                $record->moveTo($status, auth()->user(), $data['resolution'] ?? null);
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('It stayed where it was')->body($e->getMessage())->send();

                $record->refresh();

                return $record;
            }

            return $record;
        }

        $record->save();

        return $record;
    }
}
