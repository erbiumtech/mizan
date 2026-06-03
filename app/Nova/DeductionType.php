<?php

namespace App\Nova;

use Illuminate\Support\Str;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class DeductionType extends Resource
{
    public static $model = \App\Models\Deduction\Type::class;

    public static $title = 'name';

    public static $search = [
        'id', 'name', 'code',
    ];

    public static function label(): string
    {
        return 'Deduction Types';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'min:3', 'max:255'),

            Text::make('Code')
                ->exceptOnForms()
                ->displayUsing(fn ($value, $resource) => $value ?: Str::kebab($resource->name)),

            Boolean::make('Locked', 'is_locked')
                ->default(false),
        ];
    }

    public static function afterCreate(NovaRequest $request, $model): void
    {
        if (empty($model->code)) {
            $model->forceFill(['code' => Str::kebab($model->name)])->save();
        }
    }

    public static function afterUpdate(NovaRequest $request, $model): void
    {
        $model->forceFill(['code' => Str::kebab($model->name)])->save();
    }
}

