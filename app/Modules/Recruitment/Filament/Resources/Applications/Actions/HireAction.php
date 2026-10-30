<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Actions;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Services\HireService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InvalidArgumentException;
use RuntimeException;

/**
 * Hire: the conversion.
 *
 * Creates the employee, the optional login and the salary package in ONE
 * transaction — a company left with an employee who has no package, or a
 * package with no employee, is worse off than one whose button errored.
 *
 * Absent, not disabled, without the Employees module: a company hiring
 * its very first person has nothing for the offer to become yet.
 */
class HireAction
{
    public static function make(): Action
    {
        return Action::make('hire')
            ->label('Accept and hire')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (Application $record): bool => $record->offer
                && (auth()->user()?->can('hire', $record->offer) ?? false))
            ->schema([
                TextInput::make('employee_code')
                    ->label('Employee code')
                    ->maxLength(50)
                    ->helperText('Leave blank for the next in sequence. Companies have their own conventions.'),

                Checkbox::make('with_login')
                    ->label('Create a login as well')
                    ->helperText('Needs an email address. A factory floor usually does not get accounts — employees without a login are supported.'),
            ])
            ->modalDescription('This creates the employee, their salary package from the offer, and optionally a login — all or nothing.')
            ->action(function (Application $record, array $data): void {
                try {
                    $employee = app(HireService::class)->hire(
                        $record->offer,
                        (bool) ($data['with_login'] ?? false),
                        $data['employee_code'] ?: null,
                    );
                } catch (InvalidArgumentException|RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title("Hired as {$employee->employee_id}.")
                    ->body('The application keeps the trail from vacancy to payroll.')
                    ->send();
            });
    }
}
