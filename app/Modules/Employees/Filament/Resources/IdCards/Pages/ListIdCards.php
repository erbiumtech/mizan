<?php

namespace App\Modules\Employees\Filament\Resources\IdCards\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\IdCards\IdCardResource;
use Filament\Resources\Pages\ListRecords;

class ListIdCards extends ListRecords
{
    protected static string $resource = IdCardResource::class;

    /**
     * Help only.
     *
     * *Issue a card* is a header action on the **table**, not on the page, because it
     * cancels the previous card in the same breath — see IdCardResource::canCreate(),
     * which is false for that reason.
     */
    protected function getHeaderActions(): array
    {
        return [HelpAction::make('id-cards', 'ID Cards: Help')];
    }
}
