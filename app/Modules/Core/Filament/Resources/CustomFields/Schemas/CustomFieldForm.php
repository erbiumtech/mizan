<?php

namespace App\Modules\Core\Filament\Resources\CustomFields\Schemas;

use App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource;
use App\Modules\Core\Models\CustomField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('model_type')
                ->label('Applies to')
                ->options(CustomFieldResource::modelOptions())
                ->required(),

            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set, ?CustomField $record) => $record ? null : $set('code', Str::slug($state, '_'))),

            TextInput::make('code')
                ->required()
                ->maxLength(255)
                ->helperText('Machine key; unique per model. Avoid changing after values exist.')
                ->rule('regex:/^[a-z0-9_]+$/'),

            Select::make('type')
                ->options(collect(CustomField::TYPES)->mapWithKeys(fn ($t) => [$t => ucfirst($t)])->all())
                ->default('text')
                ->required()
                ->live(),

            TagsInput::make('options')
                ->helperText('Options for a Select field (press Enter after each).')
                ->visible(fn (callable $get) => $get('type') === 'select'),

            TextInput::make('min')
                ->numeric()
                ->helperText('Minimum value (number) or minimum length (text).')
                ->visible(fn (callable $get) => in_array($get('type'), ['text', 'textarea', 'number'], true)),

            TextInput::make('max')
                ->numeric()
                ->helperText('Maximum value (number) or maximum length (text).')
                ->visible(fn (callable $get) => in_array($get('type'), ['text', 'textarea', 'number'], true)),

            TextInput::make('regex')
                ->label('Regex pattern')
                ->helperText('Without delimiters, e.g. ^[A-Z]{3}$')
                ->visible(fn (callable $get) => in_array($get('type'), ['text', 'textarea'], true)),

            TextInput::make('placeholder')
                ->helperText('Placeholder shown inside the empty input.')
                ->visible(fn (callable $get) => in_array($get('type'), ['text', 'textarea', 'number', 'date', 'select'], true)),

            Textarea::make('help')->label('Help text')->nullable(),

            TextInput::make('sort')->numeric()->default(0),

            Toggle::make('is_required')->label('Required'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }
}
