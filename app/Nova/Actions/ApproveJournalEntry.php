<?php

namespace App\Nova\Actions;

use App\Services\JournalEntryService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class ApproveJournalEntry extends Action
{
    public $name = 'Approve';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(JournalEntryService::class);

        foreach ($models as $entry) {
            try {
                $service->approve($entry, request()->user());
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Journal entry approved.');
    }
}
