<?php

namespace App\Modules\Leave\Filament\Resources\LeaveRequests\Schemas;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LeaveRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Who, and what kind')
                ->schema([
                    Select::make('employee_id')
                        ->label('Employee')
                        ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                            ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                            $search,
                            EmployeeOptions::accessibleScope(),
                        ))
                        ->preload()
                        ->required()
                        ->live()
                        // Most leave is somebody filing for themselves.
                        ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id')),

                    Select::make('leave_type_id')
                        ->label('Leave type')
                        ->options(fn (): array => LeaveType::query()
                            ->active()
                            ->orderBy('sort')
                            ->pluck('label', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->live(),
                ])
                ->columns(2),

            Section::make('When')
                ->schema([
                    DatePicker::make('from_date')
                        ->native(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, $get, $set): void {
                            // A single-day request is the common case, and asking for
                            // the same date twice is the kind of friction that gets a
                            // module called fiddly.
                            if ($state && ! $get('to_date')) {
                                $set('to_date', $state);
                            }
                        }),

                    DatePicker::make('to_date')
                        ->native(false)
                        ->required()
                        ->live()
                        ->afterOrEqual('from_date'),

                    Toggle::make('is_half_day')
                        ->label('Half day')
                        ->live()
                        ->visible(fn ($get): bool => static::typeAllowsHalfDay($get('leave_type_id')))
                        ->helperText('One day only. Hours are deliberately not offered — nothing else here measures pay in hours.')
                        ->afterStateUpdated(function ($state, $get, $set): void {
                            if ($state && $get('from_date')) {
                                $set('to_date', $get('from_date'));
                            }
                        }),

                    Select::make('half_day_period')
                        ->label('Which half')
                        ->options([
                            LeaveRequest::HALF_FIRST => 'First half of the day',
                            LeaveRequest::HALF_SECOND => 'Second half of the day',
                        ])
                        ->default(LeaveRequest::HALF_FIRST)
                        ->required(fn ($get): bool => (bool) $get('is_half_day'))
                        ->visible(fn ($get): bool => (bool) $get('is_half_day')),
                ])
                ->columns(2),

            Section::make('Balance')
                ->schema([
                    // Read live rather than stored, and shown before submitting
                    // because "how many days do I have left" is the question this
                    // form is opened with. Pending requests are named separately
                    // rather than deducted: a balance that moved when somebody
                    // *asked* would show the same last day as gone to two people.
                    Placeholder::make('leave_balance')
                        ->label('Left this leave year')
                        ->content(fn (Get $get): string => static::balanceSummary(
                            $get('employee_id'),
                            $get('leave_type_id'),
                            $get('from_date'),
                        )),
                ])
                ->visible(fn ($get): bool => (bool) $get('employee_id') && (bool) $get('leave_type_id')),

            Section::make('Why')
                ->schema([
                    Textarea::make('reason')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Read by the approver. Say as much or as little as your company expects.'),

                    FileUpload::make('document_path')
                        ->label('Supporting document')
                        ->disk('public')
                        ->directory('leave-requests')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                        ->maxSize(8192)
                        ->required(fn ($get): bool => static::typeRequiresDocument($get('leave_type_id')))
                        ->visible(fn ($get): bool => static::typeRequiresDocument($get('leave_type_id')))
                        ->helperText('Visible to your approver and to HR, and to nobody else.'),
                ]),
        ]);
    }

    private static function typeAllowsHalfDay(mixed $typeId): bool
    {
        return $typeId
            ? (bool) LeaveType::query()->whereKey($typeId)->value('allows_half_day')
            : true;
    }

    private static function typeRequiresDocument(mixed $typeId): bool
    {
        return $typeId
            ? (bool) LeaveType::query()->whereKey($typeId)->value('requires_document')
            : false;
    }

    /**
     * The balance in words, including the case where no entitlement exists yet.
     *
     * "No entitlement has been opened" is deliberately distinguished from "0 days
     * left". They look the same in a number and mean opposite things: the first is a
     * year nobody has opened, and the second is an allowance somebody has spent.
     */
    private static function balanceSummary(mixed $employeeId, mixed $typeId, mixed $date): string
    {
        $employee = $employeeId ? Employee::find($employeeId) : null;
        $type = $typeId ? LeaveType::find($typeId) : null;

        if (! $employee || ! $type) {
            return 'Pick an employee and a leave type to see the balance.';
        }

        $breakdown = app(LeaveBalance::class)->for($employee, $type, $date ?: now());

        if (! $breakdown) {
            return "{$type->label} is not counted against a balance.";
        }

        if ($breakdown->isUngenerated()) {
            return "No {$type->label} entitlement has been opened for this leave year yet, so nothing is credited. "
                .'It opens overnight, or an administrator can add it now — this is not the same as a zero balance.';
        }

        $format = fn (float $days): string => rtrim(rtrim(number_format($days, 1), '0'), '.');

        $summary = $format($breakdown->remaining())." day(s) of {$type->label} left"
            .' ('.$format($breakdown->credited()).' credited, '.$format($breakdown->taken).' taken)'
            .', for the year to '.$breakdown->windowEnd->format('d M Y').'.';

        if ($breakdown->pending > 0) {
            $summary .= ' A further '.$format($breakdown->pending).' day(s) are already requested and awaiting a decision.';
        }

        return $summary;
    }
}
