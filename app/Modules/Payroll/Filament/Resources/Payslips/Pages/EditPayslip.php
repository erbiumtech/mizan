<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\Payslips\Actions\CloseObjectionAction;
use App\Modules\Payroll\Filament\Resources\Payslips\Actions\ReturnForReviewAction;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\Concerns\RoutesTypedOverridesToStore;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\AttendanceFigures;
use App\Modules\Payroll\Services\PayslipService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPayslip extends EditRecord
{
    use RedirectsToIndex;
    use RoutesTypedOverridesToStore;

    protected static string $resource = PayslipResource::class;

    /**
     * The typed overrides go to the input store, not the columns — docs/payslip-component-input-plan.md Phase 4.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$overrides, $data] = $this->splitTypedOverrides($data);

        $record->typedOverrides = $overrides;
        $record->fill($data);

        // A change to only an override leaves no input column dirty for Eloquent to notice, so the save — and
        // the recalculation in its hook — would be skipped. Force it when an override actually changed, and
        // only then, so an open-and-save with nothing changed stays a no-op and never restates a settled
        // month's withholding-tax split.
        $overrideChanged = collect(PayslipService::OVERRIDE_KEYS)->contains(
            fn (string $key): bool => round((float) ($overrides[$key] ?? 0), 2) !== round((float) $record->getOriginal($key), 2)
        );

        if ($overrideChanged && ! $record->isDirty()) {
            $record->updated_at = $record->freshTimestamp();
        }

        $record->save();

        return $record;
    }

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

    /**
     * A payslip whose working days were never known opens with them worked out.
     *
     * `total_working_days` of 0 is this application's "not known" — what every payslip carried before the
     * month was measured. Shown filled in rather than as zeros so the clerk sees the real figures and saves
     * them; nothing is written until they do, and a payslip that already knows its month is left alone.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Payslip || (float) ($data['total_working_days'] ?? 0) > 0) {
            return $data;
        }

        if (! $record->employee || ! $record->fiscalYear) {
            return $data;
        }

        return array_merge($data, app(AttendanceFigures::class)->for($record->employee, $record->month, $record->fiscalYear));
    }
}
