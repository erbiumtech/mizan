<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\Concerns\RoutesTypedOverridesToStore;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePayslip extends CreateRecord
{
    use RedirectsToIndex;
    use RoutesTypedOverridesToStore;

    protected static string $resource = PayslipResource::class;

    /**
     * The typed overrides go to the input store, not the columns — docs/payslip-component-input-plan.md Phase 4.
     * A creating payslip has no id for store rows yet, so the model's created hook writes them from the same
     * `typedOverrides` once it exists; here they are only kept off the columns and handed to the model.
     */
    protected function handleRecordCreation(array $data): Model
    {
        [$overrides, $data] = $this->splitTypedOverrides($data);

        $record = new ($this->getModel())($data);
        $record->typedOverrides = $overrides;
        $record->save();

        return $record;
    }
}
