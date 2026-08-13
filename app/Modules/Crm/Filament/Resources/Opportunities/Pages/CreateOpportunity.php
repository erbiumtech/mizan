<?php

namespace App\Modules\Crm\Filament\Resources\Opportunities\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\Opportunities\OpportunityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOpportunity extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = OpportunityResource::class;
}
