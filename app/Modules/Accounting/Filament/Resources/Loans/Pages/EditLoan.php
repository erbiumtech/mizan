<?php

namespace App\Modules\Accounting\Filament\Resources\Loans\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Loans\LoanResource;
use App\Modules\Accounting\Services\LoanService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLoan extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Changed terms mean a different schedule.
     *
     * Only reachable while nothing has been recorded — LoanPolicy::update()
     * closes the form as soon as the first instalment reaches the ledger, for
     * the reason generateSchedule() refuses: half the table is already posted.
     */
    protected function afterSave(): void
    {
        app(LoanService::class)->generateSchedule($this->record);
    }
}
