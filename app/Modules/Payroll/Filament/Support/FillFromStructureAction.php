<?php

namespace App\Modules\Payroll\Filament\Support;

use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Services\SalaryStructures;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/** The form helper on a package — docs/saas-launch-plan.md §14.1. The itemised package stays the input. */
class FillFromStructureAction
{
    public static function make(): Action
    {
        return Action::make('fillFromStructure')
            ->label('Fill from structure')
            ->icon('heroicon-o-squares-2x2')
            ->color('gray')
            ->visible(fn (): bool => modules()->enabled('payroll')
                && (auth()->user()?->can('SalaryStructureView') ?? false)
                && SalaryStructure::query()->active()->exists())
            ->modalHeading('Fill the package from a structure')
            ->modalDescription('Each component the structure names is set from the gross figure; anything it does not name is left as it is. You can still edit every line afterwards.')
            ->modalSubmitActionLabel('Fill')
            ->schema([
                Select::make('salary_structure_id')
                    ->label('Structure')
                    ->options(fn (): array => SalaryStructure::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                TextInput::make('gross')
                    ->label('Monthly gross')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ])
            ->action(function (array $data, $livewire): void {
                $setting = $livewire->getRecord();

                if (! $setting instanceof EmployeeSetting) {
                    return;
                }

                $structure = SalaryStructure::query()->findOrFail($data['salary_structure_id']);
                $amounts = app(SalaryStructures::class)->apply($setting, $structure, (float) $data['gross']);

                $livewire->fillForm();

                Notification::make()->success()
                    ->title('Package filled from '.$structure->name)
                    ->body(collect($amounts)->map(fn (float $amount, string $code): string => "{$code}: ".number_format($amount, 2))->join(', '))
                    ->send();
            });
    }
}
