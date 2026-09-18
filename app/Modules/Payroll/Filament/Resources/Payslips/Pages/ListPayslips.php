<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Pages;

use App\Filament\Concerns\HasSavedViews;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use App\Support\PayrollMonth;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ListPayslips extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = PayslipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('payslips', 'Payslips: Help'),
            $this->openMonthAction(),
            CreateAction::make(),
            $this->saveViewAction(),
        ];
    }

    /**
     * The whole month in one screen.
     *
     * `payroll:open-month` has raised the month's payslips since the beginning — on the
     * 26th, from the scheduler — and there was no way to do it from the panel at all:
     * a company running payroll early, or adding the month by hand, added employees one
     * at a time.
     *
     * **It is the same call.** `MonthlyPayrollService::openMonth()` does the work here
     * and on the command line, so this screen cannot drift into a second payroll: the
     * calculation, the tax sync, the journal posting and the advance recovery all still
     * happen in `Payslip::booted()`, and an employee who already has a payslip for the
     * month is still skipped rather than recalculated.
     *
     * **What it adds is the two figures that change every month.** Fuel and meals are
     * the ones a company edits per person per month — the package amount is a starting
     * point rather than the answer — so they are shown here, pre-filled from the
     * package, and whatever is typed is what the month pays. Any other correction is
     * still the payslip's own screen, which is where the rest of the figures live.
     */
    protected function openMonthAction(): Action
    {
        return Action::make('openMonth')
            ->label("Raise the month's payslips")
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->modalHeading("Raise the month's payslips")
            ->modalDescription('One payslip for every active employee with a salary package covering the month. Fuel and meals start from the package — type over them to pay something else for this month only.')
            ->modalSubmitActionLabel('Raise payslips')
            ->modalWidth('4xl')
            ->visible(fn (): bool => auth()->user()?->can('create', Payslip::class) ?? false)
            // The month somebody is most likely raising, with its employees already
            // listed: opening the modal on an empty list and making them pick the
            // current month first is two clicks to reach the default answer.
            ->fillForm(fn (): array => [
                'month' => now()->monthName,
                'fiscal_year_id' => $fiscalYearId = FiscalYear::current()?->getKey(),
                'employees' => self::dueRows(now()->monthName, $fiscalYearId),
            ])
            ->schema([
                Select::make('month')
                    ->label('Month')
                    ->options(self::months())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('employees', self::dueRows($get('month'), $get('fiscal_year_id')))),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->options(fn (): array => FiscalYear::query()->where('is_active', true)->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('employees', self::dueRows($get('month'), $get('fiscal_year_id')))),

                /*
                 * Every employee due a payslip, and only them: anybody who already has one
                 * for the month, or has no package covering it, is not on this list —
                 * `MonthlyPayrollService` decides that, not this screen.
                 *
                 * Fixed rows. Adding one here would mean inventing an employee, and
                 * deleting one would promise a skip this screen cannot keep: `openMonth()`
                 * raises everybody who is due, and honouring a deletion would need a second
                 * idea of who is due to go with it. A payslip raised for somebody who should
                 * not have had one is deleted from the list behind this modal.
                 *
                 * ponytail: one row per employee, all of them in the modal. Fine for the
                 * tens of employees these companies have; a company with hundreds wants this
                 * paginated or filtered to a department first.
                 */
                Repeater::make('employees')
                    ->label('Employees due a payslip')
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->columns(2)
                    ->schema([
                        Hidden::make('employee_id'),
                        Hidden::make('name'),

                        TextInput::make('petrol_allowance')
                            ->label('Petrol / fuel allowance')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Paid as part of this month\'s earnings.'),

                        TextInput::make('meal_deduction')
                            ->label('Meal deduction')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Taken off this month\'s pay.'),
                    ]),
            ])
            ->action(function (array $data): void {
                $fiscalYear = FiscalYear::find($data['fiscal_year_id']);

                if (! $fiscalYear) {
                    Notification::make()->danger()->title('Nothing was raised')
                        ->body('That fiscal year no longer exists.')->send();

                    return;
                }

                $overrides = collect($data['employees'] ?? [])
                    ->mapWithKeys(fn (array $row): array => [
                        (int) $row['employee_id'] => [
                            'petrol_allowance' => (float) ($row['petrol_allowance'] ?? 0),
                            'meal_deduction' => (float) ($row['meal_deduction'] ?? 0),
                        ],
                    ])
                    ->all();

                $payroll = app(MonthlyPayrollService::class);

                try {
                    $created = $payroll->openMonth($data['month'], $fiscalYear, $overrides);
                } catch (InvalidArgumentException $e) {
                    // A signed-off month. The model refuses it whoever asks, so this is the
                    // message rather than a 500 behind a spinner.
                    Notification::make()->danger()->title('Nothing was raised')->body($e->getMessage())->send();

                    return;
                }

                // Named, not counted. An employee missing from the month is nearly always a
                // package somebody forgot to add rather than a decision, and a number alone
                // does not say who to go and fix.
                $skipped = $payroll->employeesWithoutASetting($data['month'], $fiscalYear)->load('user');

                Notification::make()->success()
                    ->title("Raised {$created->count()} payslip(s) for {$data['month']} {$fiscalYear->name}.")
                    ->body($skipped->isEmpty() ? null : 'Skipped, with no salary package covering the month: '
                        .$skipped->map(fn (Employee $employee): string => $employee->display_label)->join(', '))
                    ->persistent($skipped->isNotEmpty())
                    ->send();
            });
    }

    /**
     * The month's employees, each with what their package currently pays for fuel and meals.
     *
     * Pre-filled rather than blank because the figure being changed is the thing worth
     * seeing: "other than the default" needs the default on the screen. Writing the package
     * amount back unchanged costs nothing — the calculation stores that same figure on the
     * payslip either way.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function dueRows(?string $month, mixed $fiscalYearId): array
    {
        $fiscalYear = $fiscalYearId ? FiscalYear::find($fiscalYearId) : null;

        if (! $month || ! $fiscalYear) {
            return [];
        }

        $on = PayrollMonth::firstDay($month, $fiscalYear)->toDateString();

        // `load()` rather than a lazy read: display_label reaches for the user, and this is
        // a list — one query for the lot instead of one per employee.
        return app(MonthlyPayrollService::class)
            ->employeesDueAPayslip($month, $fiscalYear)
            ->load('user')
            ->map(function (Employee $employee) use ($on, $fiscalYear): array {
                $setting = EmployeeSetting::getActiveSettingForDate($employee->id, $on, $fiscalYear->id);

                return [
                    'employee_id' => $employee->id,
                    'name' => $employee->display_label,
                    'petrol_allowance' => (float) ($setting->petrol_allowance ?? 0),
                    'meal_deduction' => (float) ($setting->meal_deduction ?? 0),
                ];
            })
            ->all();
    }

    /**
     * Month names, as payroll stores them: a name plus a fiscal year, never a date.
     *
     * @return array<string, string>
     */
    private static function months(): array
    {
        $months = [];

        foreach (range(1, 12) as $month) {
            $name = Carbon::create(2000, $month, 1)->monthName;
            $months[$name] = $name;
        }

        return $months;
    }
}
