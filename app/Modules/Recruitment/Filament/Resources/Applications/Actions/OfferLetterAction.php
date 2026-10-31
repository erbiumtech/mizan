<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Actions;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Notifications\OfferIssued;
use App\Modules\Recruitment\Services\OfferLetter;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * The offer as a letter — plan §7.2. Rendered from the offer each time,
 * refused when the record cannot back it, and optionally emailed to the
 * candidate with the PDF attached: until this there was no email at all,
 * only "Offer recorded" on the screen.
 */
class OfferLetterAction
{
    public static function make(): Action
    {
        return Action::make('offerLetter')
            ->label(__('Offer letter'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (Application $record): bool => $record->offer !== null
                && (auth()->user()?->can('view', $record) ?? false))
            ->schema([
                TextInput::make('valid_days')
                    ->label(__('Valid for (days)'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(90)
                    ->default(7)
                    ->required(),
                Toggle::make('email_candidate')
                    ->label(__('Email it to the candidate'))
                    ->helperText(fn (Application $record): string => $record->applicant?->email
                        ? 'Sends the letter as a PDF to '.$record->applicant->email.'.'
                        : 'The applicant has no email address on record.')
                    ->disabled(fn (Application $record): bool => blank($record->applicant?->email)),
            ])
            ->modalSubmitActionLabel('Download')
            ->action(function (Application $record, array $data) {
                $letters = app(OfferLetter::class);
                $missing = $letters->missingFor($record->offer);

                if ($missing !== []) {
                    Notification::make()
                        ->danger()
                        ->title('The letter is missing facts it cannot invent')
                        ->body('Add '.implode('; ', $missing).'.')
                        ->persistent()
                        ->send();

                    return null;
                }

                $input = ['valid_days' => (int) $data['valid_days']];

                if (($data['email_candidate'] ?? false) && filled($record->applicant?->email)) {
                    Notifications::route('mail', $record->applicant->email)
                        ->notify(new OfferIssued($record->offer, $input));

                    Notification::make()->success()
                        ->title('Offer emailed to '.$record->applicant->email)
                        ->send();
                }

                $pdf = $letters->renderPdf($record->offer, $input);

                return response()->streamDownload(
                    fn () => print ($pdf->raw()),
                    $letters->filename($record->offer),
                );
            });
    }
}
