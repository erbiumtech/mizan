<?php

namespace App\Modules\Accounting\Filament\Resources\Accounts\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Support\CompanyCurrency;
use App\Support\TaxRegimes;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * An account read in the list's view sheet: the form's fields as values, with its balance. The side
 * sheet shows this rather than the edit form disabled — docs/side-sheets.md.
 */
class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account'))
                ->columns(3)
                ->schema([
                    TextEntry::make('code')->label(__('Code'))->copyable(),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('type')
                        ->label(__('Type'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                    TextEntry::make('normal_balance')
                        ->label(__('Normal Balance'))
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'debit' => 'info',
                            'credit' => 'warning',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                        ->placeholder('—'),
                    TextEntry::make('balance')
                        ->label(__('Balance'))
                        ->money(CompanyCurrency::code()),
                    TextEntry::make('parent.name')
                        ->label(__('Parent Account'))
                        ->placeholder('—'),
                    TextEntry::make('tax_regime')
                        ->label(__('Taxed as'))
                        ->formatStateUsing(fn (?string $state): string => TaxRegimes::ALL[$state] ?? (string) $state)
                        ->visible(fn (Account $record): bool => filled($record->tax_regime)),
                    IconEntry::make('is_active')->label(__('Active'))->boolean(),
                    IconEntry::make('allow_manual_entry')->label(__('Allow Manual Entry'))->boolean(),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
