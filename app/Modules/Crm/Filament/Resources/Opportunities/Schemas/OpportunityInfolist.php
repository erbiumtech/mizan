<?php

namespace App\Modules\Crm\Filament\Resources\Opportunities\Schemas;

use App\Modules\Crm\Models\Opportunity;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * An opportunity read in the list's view sheet, in the form's own sections — docs/side-sheets.md. The
 * stage carries the list's colours: green when won, red when lost, otherwise in play.
 */
class OpportunityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The deal'))
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->label(__('Title'))->columnSpanFull(),
                    TextEntry::make('pipeline.name')->label(__('Pipeline'))->placeholder('—'),
                    TextEntry::make('stage.name')
                        ->label(__('Stage'))
                        ->badge()
                        ->color(fn (Opportunity $record): string => match (true) {
                            $record->isWon() => 'success',
                            $record->outcome === Opportunity::OUTCOME_LOST => 'danger',
                            default => 'info',
                        })
                        ->helperText(fn (Opportunity $record): ?string => $record->lostReason?->name)
                        ->placeholder('—'),
                    TextEntry::make('owner.display_label')
                        ->label(__('Owner'))
                        ->placeholder('—')
                        ->visible(fn (): bool => modules()->enabled('employees')),
                ]),

            Section::make(__('Who it is with'))
                ->columns(2)
                ->schema([
                    TextEntry::make('lead.display_label')->label(__('Lead'))->placeholder('—'),
                    TextEntry::make('contact.name')
                        ->label(__('Customer'))
                        ->placeholder('—')
                        ->visible(fn (): bool => modules()->enabled('invoicing')),
                ]),

            Section::make(__('What it is worth'))
                ->columns(2)
                ->schema([
                    TextEntry::make('amount')
                        ->label(__('Amount'))
                        ->money(fn (Opportunity $record): string => $record->currency_code ?: CompanyCurrency::code()),
                    TextEntry::make('exchange_rate')
                        ->label(__('Rate used'))
                        ->placeholder('—')
                        ->visible(fn (Opportunity $record): bool => filled($record->currency_code)
                            && strtoupper((string) $record->currency_code) !== CompanyCurrency::code()),
                    TextEntry::make('probability_pct')
                        ->label(__('Likely to close (%)'))
                        ->suffix('%')
                        ->placeholder('—'),
                    TextEntry::make('expected_close_on')->label(__('Expected close'))->date()->placeholder('—'),
                ]),

            TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
