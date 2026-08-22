<?php

namespace App\Modules\Crm\Filament\Resources\Leads\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\Leads\LeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LeadResource::class;
}
