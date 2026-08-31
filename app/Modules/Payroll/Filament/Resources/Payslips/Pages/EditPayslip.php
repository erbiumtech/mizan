<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\Payslips\Actions\CloseObjectionAction;
use App\Modules\Payroll\Filament\Resources\Payslips\Actions\ReturnForReviewAction;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayslip extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PayslipResource::class;

    /**
     * And here too, because this is the other end of the same job: somebody opens the payslip an employee
     * objected to, corrects the figure or satisfies themselves it was right, and the objection is still open
     * behind them. The button is the same definition the list and the View page use.
     */
    protected function getHeaderActions(): array
    {
        return [
            CloseObjectionAction::make(),
            ReturnForReviewAction::make(),
            DeleteAction::make(),
        ];
    }
}
