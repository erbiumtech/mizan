<?php

namespace App\Nova\Actions;

use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class ReceiveStock extends Action
{
    public $name = 'Receive Stock';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $service = app(InventoryService::class);
        $done = 0;

        foreach ($models as $product) {
            try {
                $service->purchase($product, (float) $fields->quantity, (float) $fields->unit_cost, $fields->date, $fields->reference);
                $done++;
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message("Receive Stock: {$done} product(s) processed.");
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Date::make('Date', 'date')->default(now()->toDateString())->rules('required', 'date'),
            Number::make('Quantity', 'quantity')->step(0.01)->rules('required', 'numeric', 'min:0.01'),
            Number::make('Unit Cost', 'unit_cost')->step(0.01)->rules('required', 'numeric', 'min:0'),
            Text::make('Reference', 'reference')->nullable(),
        ];
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return $request->user()?->can('StockMove') ?? false;
    }
}
