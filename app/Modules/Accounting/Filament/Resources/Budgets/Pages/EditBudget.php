<?php

namespace App\Modules\Accounting\Filament\Resources\Budgets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Budgets\BudgetResource;
use App\Modules\Accounting\Services\BudgetService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBudget extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BudgetResource::class;

    /** @var array<int, array{account_id: int|string, amount: mixed}> */
    private array $plan = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['plan'] = app(BudgetService::class)->annualPlan($this->record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->plan = $data['plan'] ?? [];
        unset($data['plan']);

        return $data;
    }

    /**
     * Written after the budget itself, and only for accounts whose yearly total
     * actually moved — see BudgetService::setAnnual(). Re-spreading everything
     * on every save would wipe out any month somebody had adjusted by hand on
     * the Monthly Plan tab, without saying so.
     */
    protected function afterSave(): void
    {
        $service = app(BudgetService::class);

        $service->syncAnnualPlan($this->record, $service->planFromForm($this->plan));
    }
}
