<?php

namespace App\Modules\Core\Filament\Resources\CustomFields\Schemas;

use App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource;
use App\Modules\Core\Models\CustomField;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A custom field's definition read in the list's view sheet, in the form's own sections —
 * docs/side-sheets.md. The bounds shown follow the type, as the form's do.
 */
class CustomFieldInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $typeIn = fn (array $types): \Closure => fn (CustomField $record): bool => in_array($record->type, $types, true);

        return $schema->components([
            Section::make(__('What it is, and where it appears'))
                ->columns(2)
                ->schema([
                    TextEntry::make('model_type')
                        ->label(__('Applies to'))
                        ->formatStateUsing(fn (string $state): string => CustomFieldResource::modelOptions()[$state] ?? $state),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('code')->label(__('Code'))->copyable(),
                    TextEntry::make('type')
                        ->label(__('Type'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __(ucfirst(str_replace('_', ' ', $state)))),
                ]),

            Section::make(__('What it will accept'))
                ->columns(2)
                ->schema([
                    TextEntry::make('options')
                        ->label(__('Options'))
                        ->badge()
                        ->placeholder('—')
                        ->visible($typeIn(['select', 'multi_select'])),
                    TextEntry::make('min')->label(__('Min'))->placeholder('—')->visible($typeIn(['text', 'textarea', 'number'])),
                    TextEntry::make('max')->label(__('Max'))->placeholder('—')->visible($typeIn(['text', 'textarea', 'number'])),
                    TextEntry::make('regex')->label(__('Regex pattern'))->placeholder('—')->visible($typeIn(['text', 'textarea'])),
                    TextEntry::make('placeholder')
                        ->label(__('Placeholder'))
                        ->placeholder('—')
                        ->visible($typeIn(['text', 'textarea', 'number', 'date', 'select'])),
                ]),

            Section::make(__('When it shows'))
                ->columns(2)
                ->schema([
                    TextEntry::make('visible_when_field')->label(__('Visible when field'))->placeholder(__('Always')),
                    TextEntry::make('visible_when_value')
                        ->label(__('Visible when value'))
                        ->placeholder('—')
                        ->visible(fn (CustomField $record): bool => filled($record->visible_when_field)),
                ]),

            Section::make(__('How it behaves'))
                ->columns(2)
                ->schema([
                    IconEntry::make('is_required')->label(__('Required'))->boolean(),
                    IconEntry::make('is_encrypted')->label(__('Encrypted'))->boolean(),
                    IconEntry::make('is_active')->label(__('Active'))->boolean(),
                    TextEntry::make('sort')->label(__('Sort')),
                    TextEntry::make('help')->label(__('Help text'))->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
