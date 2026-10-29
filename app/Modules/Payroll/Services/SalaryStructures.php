<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\SalaryStructure;

/** Fill a package from a gross figure — docs/saas-launch-plan.md §14.1. */
class SalaryStructures
{
    /**
     * Column-backed components land on the setting's own columns; the rest become
     * added components. Anything the structure does not name is left as it was.
     *
     * @return array<string, float> what was written, by component code
     */
    public function apply(EmployeeSetting $setting, SalaryStructure $structure, float $gross): array
    {
        $amounts = $structure->amountsFor($gross);
        $components = PayComponent::query()->whereIn('code', array_keys($amounts))->get()->keyBy('code');

        foreach ($amounts as $code => $amount) {
            $component = $components->get($code);

            if ($component === null) {
                continue;
            }

            if ($component->is_column_backed && in_array($code, $setting->getFillable(), true)) {
                $setting->{$code} = $amount;

                continue;
            }

            EmployeeSettingComponent::query()->updateOrCreate(
                ['employee_setting_id' => $setting->getKey(), 'pay_component_id' => $component->getKey()],
                ['amount' => $amount],
            );
        }

        $setting->save();

        return $amounts;
    }
}
