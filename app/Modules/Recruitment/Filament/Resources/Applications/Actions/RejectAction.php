<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Actions;

use App\Modules\Recruitment\Models\Application;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

class RejectAction
{
    public static function make(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Application $record): bool => $record->isOpen()
                && (auth()->user()?->can('update', $record) ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(2)
                    ->helperText('Kept on the record. It is also what starts the two-year retention clock on the applicant.'),
            ])
            ->action(function (Application $record, array $data): void {
                $record->update([
                    'stage' => Application::STAGE_REJECTED,
                    'rejected_reason' => $data['reason'],
                    'rejected_at' => now(),
                ]);

                Notification::make()->success()->title('Rejected, with the reason recorded.')->send();
            });
    }
}
