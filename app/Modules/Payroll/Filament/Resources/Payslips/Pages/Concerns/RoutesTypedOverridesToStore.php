<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Pages\Concerns;

use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Support\Arr;

/**
 * The payslip form's typed overrides go to the input store, not the columns — docs/payslip-component-input-plan.md Phase 4.
 *
 * Shared by the create and edit pages: it splits the typed overrides out of the form's save data, so they
 * never write a column. The page then hands them to the model as `Payslip::$typedOverrides`, and the save
 * hook syncs the store from them. The columns are left to the calculation, which writes them as output.
 */
trait RoutesTypedOverridesToStore
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [the typed overrides, the rest of the data]
     */
    protected function splitTypedOverrides(array $data): array
    {
        return [
            Arr::only($data, PayslipService::OVERRIDE_KEYS),
            Arr::except($data, PayslipService::OVERRIDE_KEYS),
        ];
    }
}
