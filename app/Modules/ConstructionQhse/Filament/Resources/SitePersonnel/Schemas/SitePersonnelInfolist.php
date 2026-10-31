<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Schemas;

use App\Modules\ConstructionQhse\Models\SitePersonnel as Person;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A person on the site register read in the list's view sheet, in the form's own sections —
 * docs/side-sheets.md. "Cleared" is worked out exactly as the register's own column does.
 */
class SitePersonnelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Who'))
                ->columns(2)
                ->schema([
                    TextEntry::make('job.code')
                        ->label(__('Job'))
                        ->formatStateUsing(fn ($state, Person $record): string => trim($state.' — '.($record->job?->name ?? ''), ' —')),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('employer')->label(__('Employer'))->placeholder('—'),
                    TextEntry::make('trade')->label(__('Trade'))->placeholder('—'),
                    TextEntry::make('identification')->label(__('Identification'))->placeholder('—'),
                    TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
                    TextEntry::make('is_active')
                        ->label(__('Currently on site'))
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state ? __('Yes') : __('Left'))
                        ->color(fn ($state): string => $state ? 'success' : 'gray'),
                    TextEntry::make('cleared')
                        ->label(__('Cleared'))
                        ->badge()
                        ->state(function (Person $record): string {
                            if ($record->inducted_on === null) {
                                return __('Never inducted');
                            }

                            if ($record->inductionLapsed()) {
                                return __('Induction lapsed');
                            }

                            $record->loadMissing('competencies');
                            $lapsed = $record->expiredMandatoryCompetencies();

                            return $lapsed->isEmpty() ? __('Yes') : trans_choice(':count ticket lapsed|:count tickets lapsed', $lapsed->count(), ['count' => $lapsed->count()]);
                        })
                        ->color(fn (Person $record): string => $record->isClearedToWork() ? 'success' : 'danger'),
                ]),

            Section::make(__('Induction'))
                ->columns(2)
                ->schema([
                    TextEntry::make('inducted_on')->label(__('Inducted on'))->date()->placeholder('—'),
                    TextEntry::make('induction_valid_to')
                        ->label(__('Induction valid to'))
                        ->date()
                        ->placeholder(fn (Person $record): string => $record->inducted_on === null ? '—' : __('No expiry set'))
                        ->color(fn (Person $record): string => $record->inductionLapsed() ? 'danger' : 'gray'),
                    TextEntry::make('first_on_site')->label(__('First on site'))->date()->placeholder('—'),
                    TextEntry::make('last_on_site')->label(__('Last on site'))->date()->placeholder('—'),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
