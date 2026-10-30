<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Schemas;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Services\Interviews;
use App\Modules\Recruitment\Support\Pipeline;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The candidate, as one page.
 *
 * Everything about a candidate was spread across three screens and a modal: the stage was a badge on
 * the applications list, the rounds were rows on a separate Interviews list filtered by hand, and the
 * panel's feedback was behind a per-row action. Answering "where is Hamza up to" meant holding three
 * tabs open and knowing which list to filter.
 *
 * The order is the order somebody asks: how far through are they, what has happened in the room, who
 * are they, and what have we offered.
 */
class ApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('pipeline')
                ->hiddenLabel()
                ->state(fn (Application $record): array => [
                    'steps' => Pipeline::for($record),
                    'ended' => Pipeline::hasEnded($record),
                    'stageLabel' => $record->stage === Application::STAGE_WITHDRAWN ? 'Withdrawn' : 'Rejected',
                    'reason' => $record->rejected_reason,
                ])
                ->view('filament.recruitment.pipeline')
                ->columnSpanFull(),

            Section::make('Interviews')
                ->icon('heroicon-o-calendar-days')
                ->description('Every round, oldest first. Scheduling another adds to this rather than replacing it.')
                ->schema([
                    /*
                     * Wrapped in a key rather than handed over as the list itself: Filament replaces
                     * a `blank()` state with the entry's default, and an empty array is blank — so a
                     * candidate with no interviews yet reached the view as null and it could not tell
                     * "none" from "broken". A one-key array is never blank.
                     */
                    ViewEntry::make('rounds')
                        ->hiddenLabel()
                        ->state(fn (Application $record): array => ['rounds' => app(Interviews::class)->rounds($record)])
                        ->view('filament.recruitment.interview-rounds'),
                ])
                ->columnSpanFull(),

            Section::make('Candidate')
                ->icon('heroicon-o-user')
                ->columns(2)
                ->schema([
                    TextEntry::make('applicant.email')
                        ->label('Email')
                        ->icon('heroicon-m-envelope')
                        ->copyable()
                        ->placeholder('Not recorded'),

                    TextEntry::make('applicant.phone')
                        ->label('Phone')
                        ->icon('heroicon-m-phone')
                        ->placeholder('Not recorded'),

                    TextEntry::make('applicant.current_employer')
                        ->label('Currently at')
                        ->placeholder('Not recorded'),

                    TextEntry::make('applicant.notice_period_days')
                        ->label('Notice period')
                        ->suffix(' days')
                        ->placeholder('Not recorded'),

                    TextEntry::make('applicant.source')
                        ->label('Source')
                        ->icon('heroicon-m-star')
                        ->placeholder('Not recorded'),

                    // A person's judgement, 1 to 5, the same figure the list sorts on. Here because
                    // somebody reading the rounds is exactly who would want to change it.
                    TextEntry::make('rating')
                        ->label('Rating')
                        ->suffix('/5')
                        ->placeholder('Nobody has rated them'),
                ]),

            /*
             * Absent rather than empty when there is no offer.
             *
             * A section headed "Offer" with five dashes under it reads as an offer that went wrong.
             * Asked as a query rather than through the relation because lazy loading is prevented
             * outside production and this is evaluated while the page is drawn.
             */
            Section::make('Offer')
                ->icon('heroicon-o-document-text')
                ->columns(2)
                ->visible(fn (Application $record): bool => $record->offer()->exists())
                ->schema([
                    TextEntry::make('offer.salary')->label('Basic salary')->money('PKR'),
                    TextEntry::make('offer.joining_date')->label('Joining')->date('d M Y'),
                    TextEntry::make('offer.status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            Offer::STATUS_ACCEPTED => 'success',
                            Offer::STATUS_DECLINED, Offer::STATUS_WITHDRAWN => 'danger',
                            Offer::STATUS_ISSUED => 'info',
                            default => 'gray',
                        }),
                    TextEntry::make('offer.decline_reason')
                        ->label('They said')
                        ->placeholder('—')
                        ->visible(fn (Application $record): bool => filled($record->offer?->decline_reason)),
                ]),
        ]);
    }
}
