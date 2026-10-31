<?php

namespace App\Modules\Crm\Filament\Resources\WeeklySalesReports\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Crm\Filament\Resources\WeeklySalesReports\WeeklySalesReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWeeklySalesReports extends ListRecords
{
    protected static string $resource = WeeklySalesReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('weekly-sales-form', 'Weekly Sales Form: Help'),
            CreateAction::make()->label(__('Send this week')),
        ];
    }
}
