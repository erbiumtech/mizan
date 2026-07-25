<?php

namespace App\Filament\Resources\Payslips\Schemas;

use App\Models\Payslip;
use App\Services\PayslipService;
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
        'withholding_tax',
        'advances',
        'meal_deduction',
        'esi_health_insurance',
        'total_earnings',
        'total_deductions',
        'net_salary',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // --- SELECTORS (drive the calculation) ---
                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set))
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
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

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
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

                TextInput::make('petrol_allowance')
                    ->label('Petrol Allowance')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

                TextInput::make('bonus')
                    ->label('Bonus')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

                TextInput::make('extra_work_hours')
                    ->label('Extra Work Hours')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

                TextInput::make('advances')
                    ->label('Advances')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

                TextInput::make('meal_deduction')
                    ->label('Meal Deduction')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

                TextInput::make('esi_health_insurance')
                    ->label('ESI / Health Insurance')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set)),

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
     * Recompute the readonly/derived fields via PayslipService — parity with the
     * Nova updateCalculatedFields() dependsOn callbacks.
     */
    protected static function recalculate(Get $get, Set $set): void
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
            (float) ($get('esi_health_insurance') ?? 0)
        );

        foreach (self::CALCULATED_KEYS as $key) {
            $set($key, ($data && isset($data[$key])) ? $data[$key] : 0);
        }
    }
}
