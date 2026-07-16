<?php

namespace App\Nova;

use App\Nova\Actions\DisposeFixedAsset;
use App\Nova\Actions\RunDepreciation;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use App\Nova\Fields\Currency;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class FixedAsset extends Resource
{
    public static $model = \App\Models\FixedAsset::class;

    public static $title = 'name';

    public static $search = ['asset_code', 'name'];

    public static $group = 'Accounting';

    public function title()
    {
        return "{$this->asset_code} — {$this->name}";
    }

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            Text::make('Asset Code', 'asset_code')->exceptOnForms()->sortable(),

            Text::make('Name', 'name')->rules('required', 'max:255')->sortable(),

            BelongsTo::make('Asset Account', 'account', Account::class)
                ->relatableQueryUsing(fn (NovaRequest $request, $query) => $query->postable()->ofType('asset'))
                ->rules('required'),

            Date::make('Purchase Date', 'purchase_date')->rules('required')->sortable(),

            Currency::make('Purchase Cost', 'purchase_cost')->rules('required', 'numeric', 'min:0.01'),

            Select::make('Depreciation Method', 'depreciation_method')->options([
                'straight_line' => 'Straight Line',
                'declining_balance' => 'Declining Balance',
            ])->displayUsingLabels()->default('straight_line')->hideFromIndex(),

            Number::make('Useful Life (months)', 'useful_life_months')->rules('required', 'integer', 'min:1')->hideFromIndex(),

            Currency::make('Salvage Value', 'salvage_value')->rules('numeric', 'min:0')->hideFromIndex(),

            Currency::make('Accumulated Depreciation', 'accumulated_depreciation')->exceptOnForms(),

            Currency::make('Book Value', fn () => $this->book_value)->exceptOnForms()->sortable(),

            Badge::make('Status', 'status')->map([
                'active' => 'success',
                'fully_depreciated' => 'warning',
                'disposed' => 'danger',
            ])->sortable()->filterable(),

            DateTime::make('Disposed At', 'disposed_at')->onlyOnDetail(),

            MorphMany::make('Journal Entries', 'journalEntries', JournalEntry::class),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [
            RunDepreciation::make()
                ->showInline()
                ->canRun(fn ($request, $asset) => $request->user()?->can('depreciate', $asset) ?? false),

            DisposeFixedAsset::make()
                ->showInline()
                ->canRun(fn ($request, $asset) => $request->user()?->can('dispose', $asset) ?? false),
        ];
    }
}
