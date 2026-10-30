<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Actions;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class MakeOfferAction
{
    public static function make(): Action
    {
        return Action::make('makeOffer')
            ->label(__('Make an offer'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->visible(fn (Application $record): bool => $record->isOpen()
                && ! $record->offer
                && (auth()->user()?->can('update', $record) ?? false))
            ->schema([
                TextInput::make('salary')->numeric()->required()->label(__('Basic salary')),
                DatePicker::make('joining_date')->native(false)->required(),
            ])
            ->action(function (Application $record, array $data): void {
                Offer::create([
                    'application_id' => $record->getKey(),
                    'salary' => $data['salary'],
                    'joining_date' => $data['joining_date'],
                    'status' => Offer::STATUS_ISSUED,
                    'issued_at' => now(),
                ]);

                $record->update(['stage' => Application::STAGE_OFFER]);

                Notification::make()->success()->title('Offer recorded.')->send();
            });
    }
}
