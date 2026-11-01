<?php

namespace App\Modules\Crm\Filament\Resources\Leads\Schemas;

use App\Modules\Crm\Models\Lead;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A lead read in the list's view sheet, in the form's own sections — docs/side-sheets.md. Status and
 * rating carry the list's badge colours; a converted or lost lead says what became of it.
 */
class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Who'))
                ->columns(2)
                ->schema([
                    TextEntry::make('company_name')->label(__('Company'))->placeholder('—'),
                    TextEntry::make('person_name')->label(__('Contact person'))->placeholder('—'),
                    TextEntry::make('title')->label(__('Their job title'))->placeholder('—'),
                    TextEntry::make('city')->label(__('City'))->placeholder('—'),
                ]),

            Section::make(__('How to reach them'))
                ->columns(3)
                ->schema([
                    TextEntry::make('email')->label(__('Email'))->copyable()->placeholder('—'),
                    TextEntry::make('phone')->label(__('Phone'))->copyable()->placeholder('—'),
                    TextEntry::make('whatsapp')->label(__('WhatsApp'))->copyable()->placeholder('—'),
                ]),

            Section::make(__('Where it came from, and whose it is'))
                ->columns(2)
                ->schema([
                    TextEntry::make('source.name')->label(__('Source'))->placeholder('—'),
                    TextEntry::make('owner.display_label')
                        ->label(__('Owner'))
                        ->placeholder('—')
                        ->visible(fn (): bool => modules()->enabled('employees')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            Lead::STATUS_NEW => 'gray',
                            Lead::STATUS_WORKING => 'info',
                            Lead::STATUS_QUALIFIED => 'warning',
                            Lead::STATUS_CONVERTED => 'success',
                            default => 'danger',
                        })
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            Lead::STATUS_NEW => __('New'),
                            Lead::STATUS_WORKING => __('Working'),
                            Lead::STATUS_QUALIFIED => __('Qualified'),
                            Lead::STATUS_CONVERTED => __('Converted'),
                            default => __('Lost'),
                        })
                        ->helperText(fn (Lead $record): ?string => match (true) {
                            $record->isConverted() => $record->convertedContact?->name,
                            $record->status === Lead::STATUS_LOST => $record->lost_reason,
                            default => null,
                        }),
                    TextEntry::make('rating')
                        ->label(__('Rating'))
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            Lead::RATING_HOT => 'danger',
                            Lead::RATING_WARM => 'warning',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                        ->placeholder('—'),
                ]),

            Section::make(__('What it might be worth'))
                ->columns(2)
                ->schema([
                    TextEntry::make('estimated_value')
                        ->label(__('Estimated value'))
                        ->money(fn (Lead $record): string => $record->currency_code ?: CompanyCurrency::code())
                        ->placeholder('—'),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
