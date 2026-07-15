<?php

namespace App\Nova\Actions;

use App\Services\JournalEntryService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class ReverseJournalEntry extends Action
{
    public $name = 'Reverse Entry';

    public $confirmText = 'This will create and post a mirrored reversing entry. Continue?';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(JournalEntryService::class);

        foreach ($models as $entry) {
            try {
                $reversal = $service->reverse($entry, request()->user());
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Reversing entry created and posted.');
    }
}
