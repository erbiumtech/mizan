<?php

namespace App\Modules\Crm\Filament\Resources\LeadSources\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\LeadSources\LeadSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLeadSource extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeadSourceResource::class;
}
