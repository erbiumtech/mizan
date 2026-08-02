<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Schemas;

use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PayslipForm
{
    /**
     * Fields whose value the PayslipService recomputes. Kept in sync with the
     * Nova resource's readonly + calculated fields.
     */
    protected const CALCULATED_KEYS = [
        'basic_wage',
        'medical_allowance',
        'device_allowance',
        'petrol_allowance',
        'bonus',
        'extra_work_hours',
        'expense_reimbursement',
        'withholding_tax',
        'advances',
        'meal_deduction',
        'esi_health_insurance',
        'total_earnings',
        'total_deductions',
        'net_salary',
    ];

    /**
     * Fields where a figure typed on the payslip silently outranks the
     * employee's settings — see PayslipService::calculateByParams, which takes
     * any non-zero value passed in ahead of the settings figure.
     *
     * That is deliberate: correcting one month by hand is a real need. It is also
     * how a device allowance of 5,000 came to be paid as 1.00 for a month, with
     * nothing on the screen to say the payslip and the settings disagreed.
     */
    protected const OVERRIDE_KEYS = [
        'device_allowance',
        'petrol_allowance',
        'bonus',
        'extra_work_hours',
        'advances',
        'meal_deduction',
        'esi_health_insurance',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // --- SELECTORS (drive the calculation) ---
                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                        ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                    ->searchable()
                    // The label shows the user's name, so the search must match it too;
                    // Filament would otherwise search only the employee_id title attribute.
                    ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                        $search,
                        EmployeeOptions::accessibleScope(),
                    ))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    // Uniqueness parity with the Nova custom rule: one payslip per
                    // employee + month + fiscal year.
                    ->rule(function (Get $get, ?Payslip $record) {
                        return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            $exists = Payslip::where('employee_id', $value)
                                ->where('month', $get('month'))
                                ->where('fiscal_year_id', $get('fiscal_year_id'))
                                ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                ->exists();

                            if ($exists) {
                                $fail('Payslip for this month and fiscal year of this employee is already created. Please update the old one');
                            }
                        };
                    }),

                Select::make('month')
                    ->label('Month')
                    ->options([
                        'January' => 'January', 'February' => 'February', 'March' => 'March',
                        'April' => 'April', 'May' => 'May', 'June' => 'June',
                        'July' => 'July', 'August' => 'August', 'September' => 'September',
                        'October' => 'October', 'November' => 'November', 'December' => 'December',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record)),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record)),

                // --- ATTENDANCE (text w/ numeric validation, hidden from index) ---
                TextInput::make('total_working_days')
                    ->label('Total Working Days')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0),

                TextInput::make('paid_days')
                    ->label('Paid Days')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0),

                TextInput::make('lop_days')
                    ->label('LOP Days')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0),

                TextInput::make('leaves_taken')
                    ->label('Leaves Taken')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0),

                // --- READONLY calculated-from-settings ---
                TextInput::make('basic_wage')
                    ->label('Basic Wage')
                    ->numeric()
                    ->readOnly(),

                TextInput::make('medical_allowance')
                    ->label('Medical Allowance')
                    ->numeric()
                    ->readOnly(),

                // --- EDITABLE inputs that feed the calculation ---
                TextInput::make('device_allowance')
                    ->label('Device Allowance')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    ->hintColor('warning')
                    ->hint(fn (Get $get, $state, ?Payslip $record): ?string => self::overrideHint($get, $record, 'device_allowance', $state)),

                TextInput::make('petrol_allowance')
                    ->label('Petrol Allowance')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    ->hintColor('warning')
                    ->hint(fn (Get $get, $state, ?Payslip $record): ?string => self::overrideHint($get, $record, 'petrol_allowance', $state)),

                TextInput::make('bonus')
                    ->label('Bonus')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    ->hintColor('warning')
                    ->hint(fn (Get $get, $state, ?Payslip $record): ?string => self::overrideHint($get, $record, 'bonus', $state)),

                TextInput::make('extra_work_hours')
                    ->label('Extra Work Hours')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    ->hintColor('warning')
                    ->hint(fn (Get $get, $state, ?Payslip $record): ?string => self::overrideHint($get, $record, 'extra_work_hours', $state)),

                TextInput::make('expense_reimbursement')
                    ->label('Expense Reimbursement')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record)),

                TextInput::make('advances')
                    ->label('Advances')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    ->hintColor('warning')
                    ->hint(fn (Get $get, $state, ?Payslip $record): ?string => self::overrideHint($get, $record, 'advances', $state)),

                TextInput::make('meal_deduction')
                    ->label('Meal Deduction')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    ->hintColor('warning')
                    ->hint(fn (Get $get, $state, ?Payslip $record): ?string => self::overrideHint($get, $record, 'meal_deduction', $state)),

                TextInput::make('esi_health_insurance')
                    ->label('ESI / Health Insurance')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, ?Payslip $record) => self::recalculate($get, $set, $record))
                    ->hintColor('warning')
                    ->hint(fn (Get $get, $state, ?Payslip $record): ?string => self::overrideHint($get, $record, 'esi_health_insurance', $state)),

                // --- READONLY calculated totals ---
                TextInput::make('withholding_tax')
                    ->label('Withholding Tax')
                    ->numeric()
                    ->readOnly(),

                TextInput::make('total_earnings')
                    ->label('Total Earnings')
                    ->numeric()
                    ->readOnly(),

                TextInput::make('total_deductions')
                    ->label('Total Deductions')
                    ->numeric()
                    ->readOnly(),

                TextInput::make('net_salary')
                    ->label('Net Salary')
                    ->numeric()
                    ->readOnly(),
            ]);
    }

    /**
     * Warn when this field disagrees with what the employee's settings say.
     *
     * The figure it names is what the field would hold if it were cleared, so it
     * covers every override the same way: allowances and deductions come from the
     * settings, and the advance instalment comes from the advance ledger.
     */
    public static function overrideHint(Get $get, ?Payslip $record, string $field, $state): ?string
    {
        if (round((float) $state, 2) <= 0) {
            return null;
        }

        $derived = self::derivedValues($get, $record);
        $settingsValue = round((float) ($derived[$field] ?? 0), 2);

        if (round((float) $state, 2) === $settingsValue) {
            return null;
        }

        return 'Overrides '.number_format($settingsValue, 2);
    }

    /**
     * Every field as payroll would compute it with nothing overridden, from one
     * call rather than one per field — the form re-renders on each keystroke and
     * this runs for seven fields at a time.
     */
    protected static function derivedValues(Get $get, ?Payslip $record): array
    {
        $employee = $get('employee_id');
        $month = $get('month');
        $fiscalYear = $get('fiscal_year_id');

        if (! $employee || ! $month || ! $fiscalYear) {
            return [];
        }

        // Held in the container, not a static: a static outlives the request, and
        // the same employee, month and payslip id in a later one would be answered
        // from figures that have since changed. Each Livewire round trip is its own
        // request, so this still collapses the seven fields into one call.
        $cache = app()->bound($bucket = 'payroll.payslip-derived-values')
            ? app($bucket)
            : tap(new \ArrayObject, fn ($fresh) => app()->instance($bucket, $fresh));

        $key = implode('|', [$employee, $month, $fiscalYear, $record?->getKey() ?? 'new']);

        if (! isset($cache[$key])) {
            $cache[$key] = app(PayslipService::class)->calculateByParams(
                $employee, $month, $fiscalYear,
                0, 0, 0, 0, 0, 0, 0, 0,
                $record?->getKey(),
            ) ?: [];
        }

        return $cache[$key];
    }

    /**
     * Recompute the readonly/derived fields via PayslipService — parity with the
     * Nova updateCalculatedFields() dependsOn callbacks.
     */
    protected static function recalculate(Get $get, Set $set, ?Payslip $record = null): void
    {
        $employee = $get('employee_id');
        $month = $get('month');
        $fiscalYear = $get('fiscal_year_id');

        if (! $employee || ! $month || ! $fiscalYear) {
            foreach (self::CALCULATED_KEYS as $key) {
                $set($key, 0);
            }

            return;
        }

        $data = app(PayslipService::class)->calculateByParams(
            $employee,
            $month,
            $fiscalYear,
            (float) ($get('bonus') ?? 0),
            (float) ($get('extra_work_hours') ?? 0),
            (float) ($get('device_allowance') ?? 0),
            (float) ($get('petrol_allowance') ?? 0),
            (float) ($get('advances') ?? 0),
            (float) ($get('meal_deduction') ?? 0),
            (float) ($get('esi_health_insurance') ?? 0),
            (float) ($get('expense_reimbursement') ?? 0),
            $record?->getKey()
        );

        foreach (self::CALCULATED_KEYS as $key) {
            $set($key, ($data && isset($data[$key])) ? $data[$key] : 0);
        }
    }
}
