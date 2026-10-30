<?php

namespace App\Modules\Crm\Filament\Resources\WeeklySalesReports\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\WeeklySalesReports\WeeklySalesReportResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWeeklySalesReport extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = WeeklySalesReportResource::class;
}
