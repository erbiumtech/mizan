<?php

namespace App\Modules\Accounting\Filament\Resources\Budgets\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Budgets\BudgetResource;
use App\Modules\Accounting\Services\BudgetService;
use Filament\Resources\Pages\CreateRecord;

class CreateBudget extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BudgetResource::class;

    /** @var array<int, array{account_id: int|string, amount: mixed}> */
    private array $plan = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // `plan` is the form's own shape, not a column: the annual figure is
        // spread into twelve monthly rows below and never stored as typed.
        $this->plan = $data['plan'] ?? [];
        unset($data['plan']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $service = app(BudgetService::class);

        $service->syncAnnualPlan($this->record, $service->planFromForm($this->plan));
    }
}
