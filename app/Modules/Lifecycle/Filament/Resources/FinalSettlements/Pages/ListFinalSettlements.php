<?php

namespace App\Modules\Lifecycle\Filament\Resources\FinalSettlements\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Resources\FinalSettlements\FinalSettlementResource;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListFinalSettlements extends ListRecords
{
    protected static string $resource = FinalSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('final-settlements', 'Final Settlements: Help'),

            /**
             * Built rather than typed.
             *
             * Every figure is gathered from somewhere the system already knows, so the
             * starting point is a proposal a person corrects — not a blank form where
             * somebody has to remember that an unrecovered advance exists.
             */
            Action::make('build')
                ->label('Build a settlement')
                ->icon('heroicon-o-calculator')
                ->visible(fn (): bool => auth()->user()?->can('create', FinalSettlement::class) ?? false)
                ->schema([
                    Select::make('employee_id')
                        ->label('Employee')
                        ->options(fn (): array => Employee::query()
                            // Leavers first: this is a screen somebody opens about
                            // somebody who has gone.
                            ->orderByRaw('is_active asc')
                            ->orderBy('employee_id')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                            ->all())
                        ->searchable()
                        ->required(),

                    DatePicker::make('left_on')
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->helperText('Leave the employee\'s own leaving date blank and this fills it in from here.'),
                ])
                ->action(function (array $data): void {
                    $employee = Employee::findOrFail($data['employee_id']);

                    try {
                        app(FinalSettlementBuilder::class)->build($employee, $data['left_on']);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('Built from leave, advances and the asset register.')
                        ->body('Check it, add anything the system cannot know, then approve. Nothing is paid or posted by this.')
                        ->send();
                }),
        ];
    }
}
