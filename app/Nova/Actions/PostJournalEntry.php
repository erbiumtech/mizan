<?php

namespace App\Nova\Actions;

use App\Services\JournalEntryService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class PostJournalEntry extends Action
{
    public $name = 'Post Entry';

    public $confirmText = 'Posting will update account balances. This cannot be undone (only reversed). Continue?';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(JournalEntryService::class);

        foreach ($models as $entry) {
            try {
                $service->post($entry);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Journal entry posted to the ledger.');
    }
}
