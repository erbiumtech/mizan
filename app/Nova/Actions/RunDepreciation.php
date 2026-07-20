<?php

namespace App\Nova\Actions;

use App\Services\DepreciationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Http\Requests\NovaRequest;

class RunDepreciation extends Action
{
    public $name = 'Run Depreciation';

    public $confirmText = 'Book one month of depreciation for the selected assets? Entries are posted immediately.';

    public function fields(NovaRequest $request)
    {
        return [
            Date::make('Month', 'month')
                ->help('Any date in the month to depreciate; defaults to last month.')
                ->default(now()->subMonth()->toDateString()),
        ];
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(DepreciationService::class);
        $month = Carbon::parse($fields->month ?? now()->subMonth());
        $fiscalYearId = \App\Models\FiscalYear::where('is_active', true)->first()?->id;

        $booked = 0;

        foreach ($models as $asset) {
            try {
                if ($service->depreciateAsset($asset, $month, $fiscalYearId)) {
                    $booked++;
                }
            } catch (\Exception $e) {
                return Action::danger("{$asset->asset_code}: {$e->getMessage()}");
            }
        }

        return $booked > 0
            ? Action::message("Depreciation booked for {$booked} asset(s).")
            : Action::message('Nothing to book (already depreciated or not eligible).');
    }
}
