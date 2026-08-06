<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\PersonalAccountResource;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Services\StarterChart;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPersonalAccounts extends ListRecords
{
    protected static string $resource = PersonalAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('personal-accounts', 'My Accounts: Help'),

            // Offered only while there is nothing here. Once somebody has their
            // own chart, a button that quietly adds fifteen accounts is a
            // nuisance rather than a help.
            Action::make('starterChart')
                ->label('Set up starter accounts')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => PersonalAccount::count() === 0)
                ->requiresConfirmation()
                ->modalHeading('Set up a starter set of accounts')
                ->modalDescription('Adds cash and bank accounts, common expense categories including Education, and income categories tagged with how they are taxed. You can rename, close or delete any of them afterwards.')
                ->modalSubmitActionLabel('Set them up')
                ->action(function (): void {
                    $created = app(StarterChart::class)->createFor();

                    Notification::make()
                        ->success()
                        ->title($created === 0 ? 'Nothing to add' : "{$created} accounts added")
                        ->body($created === 0
                            ? 'You already have all of the starter accounts.'
                            : 'Rename or remove anything that does not fit how you keep your money.')
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
