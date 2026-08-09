<?php

namespace App\Modules\Accounting\Filament\Resources\Loans\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Loans\LoanResource;
use App\Modules\Accounting\Services\LoanService;
use Filament\Resources\Pages\CreateRecord;

class CreateLoan extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = LoanResource::class;

    /**
     * The schedule is generated the moment the loan exists, so the Schedule tab
     * is never empty on a loan somebody has just set up — an empty table there
     * reads as "this feature does not work" rather than "press something".
     */
    protected function afterCreate(): void
    {
        app(LoanService::class)->generateSchedule($this->record);
    }
}
