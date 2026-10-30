<?php

namespace App\Modules\Crm\Filament\Resources\WeeklySalesReports\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\WeeklySalesReports\WeeklySalesReportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWeeklySalesReport extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = WeeklySalesReportResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
