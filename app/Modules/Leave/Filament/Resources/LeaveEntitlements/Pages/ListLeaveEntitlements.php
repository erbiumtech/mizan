<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEntitlements\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Leave\Filament\Resources\LeaveEntitlements\LeaveEntitlementResource;
use App\Modules\Leave\Services\LeaveEntitlementService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLeaveEntitlements extends ListRecords
{
    protected static string $resource = LeaveEntitlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('leave-balances', 'Leave Balances: Help'),

            /*
             * The year's roll, now — docs/saas-launch-plan.md §14.9.
             *
             * `leave:open-year` runs nightly and a company setting itself up, or adding a
             * leave type in March, should not have to wait until tomorrow for anybody to
             * have a balance. Calls the service the command calls.
             *
             * The order is the command's and it matters: ending the years that have ended
             * is what writes `carried_in_days`, so opening first would carry nothing in.
             * Idempotent either way — a year already open is not opened twice.
             */
            Action::make('openYear')
                ->label(__('Open the leave year now'))
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Open the leave year')
                ->modalDescription('Closes any year that has ended, carrying the balance forward where the type '
                    .'allows it, then opens this year for everybody who has none. The nightly run does the same '
                    .'thing; nothing already open is touched.')
                ->modalSubmitActionLabel('Run it')
                ->visible(fn (): bool => auth()->user()?->can('LeaveEntitlementUpdate') ?? false)
                ->action(function (): void {
                    $entitlements = app(LeaveEntitlementService::class);

                    $closed = $entitlements->resetEndedYears();
                    $opened = $entitlements->openYearFor();

                    Notification::make()->success()
                        ->title($opened === 0 && $closed === 0
                            ? 'Nothing to do — every entitlement is already current.'
                            : "Closed {$closed} and opened {$opened}.")
                        ->send();
                }),

            // Creating one by hand is for a type added mid-year, or opening data at
            // set-up. The nightly command is what normally writes these.
            \Filament\Actions\CreateAction::make()->label(__('Add an entitlement')),
        ];
    }
}
