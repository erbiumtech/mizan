<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Pages;

use App\Modules\Payroll\Filament\Resources\Payslips\Actions\CloseObjectionAction;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use App\Modules\Payroll\Filament\Resources\Payslips\Schemas\PayslipInfolist;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/**
 * The payslip as its own employee can open it — and the only screen from which they can reach the
 * conversation about it.
 *
 * **This page exists because relation managers need a record page.** The Comments tab lives on a record
 * page, the only record page was Edit, and Edit requires `PayslipUpdate` — which an employee does not hold.
 * So an employee could reject their payslip and then had nowhere to read the reply: the thread was open to
 * them by policy and closed to them by routing.
 *
 * `PayslipPolicy::view()` is what gates it, and it already said the right thing: your own payslip, or one in
 * your reporting downline. Nothing about permissions changed to add this page.
 *
 * The Edit button is here for whoever may use it, and simply absent for whoever may not — Filament asks the
 * policy, so the same page serves the employee and the payroll clerk without a branch.
 */
class ViewPayslip extends ViewRecord
{
    protected static string $resource = PayslipResource::class;

    public function infolist(Schema $schema): Schema
    {
        return PayslipInfolist::configure($schema);
    }

    /**
     * Closing the objection is here, above the thread it closes.
     *
     * This page is where somebody actually reads the conversation, so it is where the decision that ends it
     * belongs — the payslips list has the same button for triaging a month, and it is the same definition.
     * Hidden unless there is an open objection and the reader may edit payslips, which is what makes this
     * page safe to show an employee.
     */
    protected function getHeaderActions(): array
    {
        return [
            CloseObjectionAction::make(),
            EditAction::make(),
        ];
    }
}
