<?php

namespace App\Modules\Recruitment\Filament\Resources\Interviews\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Recruitment\Filament\Resources\Interviews\InterviewResource;
use Filament\Resources\Pages\ListRecords;

class ListInterviews extends ListRecords
{
    protected static string $resource = InterviewResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('interviews', 'Interviews: Help')];
    }
}
