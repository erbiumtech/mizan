<?php

namespace App\Nova\Actions;

use App\Services\JournalEntryService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class SubmitJournalEntry extends Action
{
    public $name = 'Submit for Approval';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(JournalEntryService::class);

        foreach ($models as $entry) {
            try {
                $service->submitForApproval($entry);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Submitted for approval.');
    }
}
