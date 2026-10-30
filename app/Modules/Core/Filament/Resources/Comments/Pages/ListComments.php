<?php

namespace App\Modules\Core\Filament\Resources\Comments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\Comments\CommentResource;
use Filament\Resources\Pages\ListRecords;

class ListComments extends ListRecords
{
    protected static string $resource = CommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No New comment: a comment is made on what it is about. See CommentResource::canCreate().
            HelpAction::make('comments', 'Comments: Help'),
        ];
    }
}
