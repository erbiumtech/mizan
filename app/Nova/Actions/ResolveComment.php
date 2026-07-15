<?php

namespace App\Nova\Actions;

use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class ResolveComment extends Action
{
    public $name = 'Mark Resolved';

    public function handle(ActionFields $fields, Collection $models)
    {
        foreach ($models as $comment) {
            $comment->update([
                'resolved_at' => now(),
                'resolved_by' => request()->user()->id,
            ]);
        }

        return Action::message('Comment marked as resolved.');
    }
}
