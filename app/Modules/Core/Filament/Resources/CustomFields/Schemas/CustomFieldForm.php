<?php

namespace App\Modules\Core\Filament\Resources\CustomFields\Schemas;

use App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource;
use App\Modules\Core\Models\CustomField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What it is, and where it appears')
                ->description('The record this field is added to, what it is called, and what shape of answer it takes.')
                ->columns(2)
                ->schema([
                    Select::make('model_type')
                        ->label('Applies to')
                        ->options(CustomFieldResource::modelOptions())
                        ->required()
                        ->live(),

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
                        ->options(collect(CustomField::TYPES)->mapWithKeys(fn ($t) => [$t => ucfirst(str_replace('_', ' ', $t))])->all())
                        ->default('text')
                        ->required()
                        ->live(),
                ]),

            Section::make('What it will accept')
                ->description('The bounds on the answer. Which of these apply depends on the type chosen above.')
                ->columns(2)
                ->schema([
                    TagsInput::make('options')
                        ->helperText('Options for a Select field (press Enter after each).')
                        ->visible(fn (callable $get) => in_array($get('type'), ['select', 'multi_select'], true)),

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
                ]),

            Section::make('When it shows')
                ->description('Leave blank for a field that always shows; otherwise it appears only when another field holds the value named here.')
                ->columns(2)
                ->schema([
                    Select::make('visible_when_field')
                        ->label('Visible when field')
                        ->options(fn (callable $get, ?CustomField $record) => CustomField::query()
                            ->where('model_type', $get('model_type'))
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->pluck('name', 'code')
                            ->all())
                        ->nullable()
                        ->live()
                        ->helperText('Show this field only while another field of the same model has a given value.'),

                    TextInput::make('visible_when_value')
                        ->label('Visible when value')
                        ->helperText('The value that reveals this field (for a toggle: 1 or 0).')
                        ->visible(fn (callable $get) => filled($get('visible_when_field'))),
                ]),

            Section::make('How it behaves')
                ->description('Whether it must be answered, whether the answer is encrypted at rest, and where it sits among the others.')
                ->columns(2)
                ->schema([
                    Toggle::make('is_required')->label('Required'),

                    Toggle::make('is_encrypted')
                        ->label('Encrypted')
                        ->helperText('Stored encrypted at rest. Encrypted fields cannot be filtered or searched.'),

                    Toggle::make('is_active')->label('Active')->default(true),

                    TextInput::make('sort')->numeric()->default(0),

                    Textarea::make('help')->label('Help text')->nullable(),
                ]),
        ]);
    }
}
