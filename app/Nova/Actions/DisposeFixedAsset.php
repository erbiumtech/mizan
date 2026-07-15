<?php

namespace App\Nova\Actions;

use App\Services\DepreciationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class DisposeFixedAsset extends Action
{
    public $name = 'Dispose Asset';

    public $confirmText = 'This writes the asset off the books (remaining book value becomes a loss) and cannot be undone. Continue?';

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(DepreciationService::class);

        foreach ($models as $asset) {
            try {
                $service->dispose($asset);
            } catch (\Exception $e) {
                return Action::danger("{$asset->asset_code}: {$e->getMessage()}");
            }
        }

        return Action::message('Asset(s) disposed and written off.');
    }
}
