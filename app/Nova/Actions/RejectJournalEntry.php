<?php

namespace App\Nova\Actions;

use App\Services\JournalEntryService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class RejectJournalEntry extends Action
{
    public $name = 'Reject';

    public function fields(NovaRequest $request)
    {
        return [
            Textarea::make('Reason', 'reason')->rules('required'),
        ];
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(JournalEntryService::class);

        foreach ($models as $entry) {
            try {
                $service->reject($entry, request()->user(), $fields->reason);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Journal entry rejected.');
    }
}
