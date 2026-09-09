<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes\Schemas;

use App\Modules\Leave\Models\LeaveType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeaveTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What it is')
                ->schema([
                    TextInput::make('label')
                        ->required()
                        ->maxLength(255)
                        ->helperText('As an employee will see it, e.g. "Annual Leave".'),

                    TextInput::make('code')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->helperText('A stable handle the system uses. Changing it after leave has been taken is safe, but pointless.'),

                    Select::make('kind')
                        ->options([
                            'leave' => 'Ordinary leave',
                            'maternity' => 'Maternity',
                            'paternity' => 'Paternity',
                            'bereavement' => 'Bereavement',
                            'hajj' => 'Hajj',
                            'unpaid' => 'Unpaid',
                        ])
                        ->default('leave')
                        ->required()
                        ->helperText('Groups types for reporting. It changes nothing about how the leave behaves.'),

                    Toggle::make('is_active')
                        ->default(true)
                        ->helperText('Switch off rather than delete once anybody has taken this leave — deleting it would orphan their record.'),
                ])
                ->columns(2),

            Section::make('How much, and how it is earned')
                ->schema([
                    Select::make('accrual_method')
                        ->options([
                            LeaveType::ACCRUAL_ANNUAL_UPFRONT => 'The whole year at once',
                            LeaveType::ACCRUAL_MONTHLY => 'A twelfth each month',
                            LeaveType::ACCRUAL_SEMI_MONTHLY => 'Twice a month — a twenty-fourth on the 1st and the 16th',
                            LeaveType::ACCRUAL_ON_COMPLETION => 'After twelve months of service',
                            LeaveType::ACCRUAL_COMPENSATORY => 'Earned by working a day off (needs Attendance)',
                            LeaveType::ACCRUAL_UNLIMITED => 'Not counted down',
                            LeaveType::ACCRUAL_NONE => 'No entitlement (unpaid)',
                        ])
                        ->default(LeaveType::ACCRUAL_ANNUAL_UPFRONT)
                        ->required()
                        ->live()
                        ->helperText(fn ($state): string => $state === LeaveType::ACCRUAL_COMPENSATORY
                            // Said plainly rather than hidden, because a type that
                            // silently never accrues is worse than one that says so.
                            ? 'Compensatory days accrue from approved attendance, which is not built yet — this type will not credit anything today.'
                            : 'How the year\'s days arrive in somebody\'s balance.'),

                    TextInput::make('days_per_year')
                        ->numeric()
                        ->step(0.5)
                        ->minValue(0)
                        ->visible(fn ($get): bool => ! in_array(
                            $get('accrual_method'),
                            [LeaveType::ACCRUAL_UNLIMITED, LeaveType::ACCRUAL_NONE],
                            true,
                        ))
                        ->helperText('The statutory minimum is provincial — Sindh, Punjab, KP and Balochistan each set their own. Confirm this figure against the rules where you operate; what we shipped is a starting point.'),

                    TextInput::make('max_carry_forward')
                        ->label('Days that may carry forward')
                        ->numeric()
                        ->step(0.5)
                        ->minValue(0)
                        ->default(0)
                        ->helperText('Only takes effect when Company Settings → Leave has carry-forward on. Leave at 0 for a type that never carries, even then.'),

                    Toggle::make('is_paid')
                        ->default(true)
                        ->helperText('Unpaid leave is the only kind that can ever reduce pay, and only once pay pro-rating is switched on.'),

                    Toggle::make('is_encashable')
                        ->label('Encashable on separation')
                        ->helperText('Paid out in a final settlement. Never paid out at the year end — unused days lapse there.'),
                ])
                ->columns(2),

            Section::make('Rules when somebody asks')
                ->schema([
                    Toggle::make('allows_half_day')
                        ->default(true)
                        ->helperText('Half days only. Hourly leave is deliberately not offered.'),

                    Toggle::make('requires_document')
                        ->label('Ask for a document')
                        ->helperText('A medical certificate or equivalent. Visible to the approver and HR, and to nobody else.'),

                    TextInput::make('min_notice_days')
                        ->label('Notice expected (days)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText('Whether this blocks a request or merely warns is one company setting, in Company Settings → Leave. It warns by default.'),

                    TextInput::make('sort')
                        ->label('Order in lists')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(2),
        ]);
    }
}
