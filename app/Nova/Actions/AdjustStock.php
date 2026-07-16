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

class AdjustStock extends Action
{
    public $name = 'Adjust Stock';

    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $service = app(InventoryService::class);
        $done = 0;

        foreach ($models as $product) {
            try {
                $service->adjust($product, (float) $fields->quantity, $fields->date, $fields->unit_cost !== null ? (float) $fields->unit_cost : null, $fields->reference);
                $done++;
            } catch (\InvalidArgumentException $e) {
                return ActionResponse::danger($e->getMessage());
            }
        }

        return ActionResponse::message("Adjust Stock: {$done} product(s) processed.");
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Date::make('Date', 'date')->default(now()->toDateString())->rules('required', 'date'),
            Number::make('Quantity', 'quantity')->step(0.01)->rules('required', 'numeric'),
            Number::make('Unit Cost', 'unit_cost')->step(0.01)->nullable()->rules('nullable', 'numeric', 'min:0')->help('Required for positive adjustments'),
            Text::make('Reference', 'reference')->nullable(),
        ];
    }

    public function authorizedToRun(\Illuminate\Http\Request $request, $model): bool
    {
        return $request->user()?->can('StockAdjust') ?? false;
    }
}
